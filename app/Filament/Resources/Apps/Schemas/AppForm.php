<?php

namespace App\Filament\Resources\Apps\Schemas;

use App\Models\App;
use App\Services\AppProvisioner;
use App\Services\DeploySteps;
use App\Services\GitService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Throwable;

/**
 * Ported from reference/src/views/apps/create.ejs + edit.ejs, with the rules
 * from reference/src/validators/appValidators.ts.
 *
 * The create and edit forms in the reference are two different forms with two
 * different rule sets, and `path` means two different things in them:
 *
 * - CREATE takes a RELATIVE segment, joined onto BRIDGE_REPOS_PATH, max 255,
 *   rejecting `..`, and subject to the directory-state rules.
 * - EDIT takes the FULL stored path, max 500, with no directory-state rules —
 *   the checkout already exists and the operator may be repointing it.
 *
 * This is one schema serving both, branching on $operation, so
 * AppResource::form() stays the single definition. The cost is that `path`'s
 * meaning is operation-dependent, which is why it is spelled out here.
 */
class AppForm
{
    /**
     * Memo for the per-request branch lookup. `options()` and the `visible()`
     * predicates of both branch components ask for the same remote's branches
     * on the same render, and `git ls-remote` is a network call — without this
     * it runs three times per blur.
     *
     * @var array<string, list<string>>
     */
    private static array $branchCache = [];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('repo_url')
                    ->label('Repo URL')
                    ->required()
                    ->maxLength(500)
                    // Deliberately not ->url(): `git@github.com:owner/repo.git`
                    // is a valid and common value here, and is not a URL by
                    // RFC, so Laravel's url rule rejects it.
                    ->live(onBlur: true),

                // The branch field is a Select when the remote can be listed
                // and free text when it cannot. Filament's Select has no
                // custom-value affordance, and a private repo with no key
                // configured (or simply an offline host) must not lock the
                // operator out of the form — so these are two mutually
                // exclusive components on the same state path. A hidden
                // component is not dehydrated, so only one ever writes.
                Select::make('branch')
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->options(fn (Get $get): array => self::branchOptions($get('repo_url')))
                    ->visible(fn (Get $get): bool => self::branches($get('repo_url')) !== []),

                TextInput::make('branch')
                    ->required()
                    ->maxLength(255)
                    ->default('main')
                    ->helperText('Could not list branches on the remote — enter one manually.')
                    ->visible(fn (Get $get): bool => self::branches($get('repo_url')) === []),

                // Dehydrated like any other field — CreateApp reads it out of
                // $data and unsets it before the model is created. It is not
                // an `apps` column.
                Toggle::make('skip_clone')
                    ->label('Import an existing checkout instead of cloning')
                    ->helperText('The directory must already exist and already be a git repository.')
                    ->live()
                    ->visibleOn('create'),

                TextInput::make('path')
                    ->required()
                    ->maxLength(fn (string $operation): int => $operation === 'create' ? 255 : 500)
                    ->prefix(fn (string $operation): ?string => $operation === 'create'
                        ? config('bridge.repos_path').'/'
                        : null)
                    ->helperText(fn (string $operation): ?string => $operation === 'create'
                        ? 'Relative to the repos directory.'
                        : null)
                    ->rules(fn (string $operation, Get $get): array => [
                        // The reference checks `..` on create but NOT on
                        // update (appValidators.ts:87). The plan lists that
                        // as a defect to fix rather than carry forward, so
                        // this rule is unconditional.
                        static function (string $attribute, mixed $value, callable $fail): void {
                            if (str_contains((string) $value, '..')) {
                                $fail('Path must not contain ..');
                            }
                        },
                        ...($operation === 'create' ? [self::directoryStateRule($get)] : []),
                    ]),

                // Edit-only, matching the reference: its create route reads
                // only name/repo_url/branch/path off the request
                // (apps.ts:47-71), and its create.ejs has neither of these
                // fields. A new app gets them on the edit screen.
                TextInput::make('health_url')
                    ->label('Health check URL')
                    ->maxLength(500)
                    ->visibleOn('edit'),

                Textarea::make('deploy_steps_text')
                    ->label('Post-deploy steps')
                    ->helperText('One per line, as `service: command`. A bridge.yml in the repo overrides this.')
                    ->rows(4)
                    // Not a column. EditApp turns it into the `deploy_steps`
                    // JSON via DeploySteps::parseUiSteps() and unsets it.
                    ->visibleOn('edit')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The create-time directory-state rules, deferred to
     * AppProvisioner::validateNewPath() so every message has exactly one
     * definition. Reads `skip_clone` live, since the toggle flips which rule
     * applies.
     */
    private static function directoryStateRule(Get $get): callable
    {
        return static function (string $attribute, mixed $value, callable $fail) use ($get): void {
            $message = app(AppProvisioner::class)->validateNewPath(
                AppProvisioner::fullPath((string) $value),
                (bool) $get('skip_clone'),
            );

            if ($message !== null) {
                $fail($message);
            }
        };
    }

    /**
     * @return array<string, string>
     */
    private static function branchOptions(mixed $repoUrl): array
    {
        $branches = self::branches($repoUrl);

        return array_combine($branches, $branches);
    }

    /**
     * Branch names on the remote, `main`/`master` hoisted to the front.
     *
     * Returns [] on ANY failure — no remote yet, unreachable host, missing
     * SSH key, malformed URL. That empty result is the signal the free-text
     * fallback keys off, so swallowing here is load-bearing, not defensive
     * noise.
     *
     * @return list<string>
     */
    private static function branches(mixed $repoUrl): array
    {
        $repoUrl = trim((string) $repoUrl);

        if ($repoUrl === '') {
            return [];
        }

        if (array_key_exists($repoUrl, self::$branchCache)) {
            return self::$branchCache[$repoUrl];
        }

        try {
            $branches = app(GitService::class)->lsRemote($repoUrl);
        } catch (Throwable) {
            $branches = [];
        }

        $hoisted = array_values(array_intersect(['main', 'master'], $branches));
        $rest = array_values(array_diff($branches, ['main', 'master']));

        return self::$branchCache[$repoUrl] = [...$hoisted, ...$rest];
    }

    /**
     * Test seam. The memo above is per-process, so a test that rebinds
     * GitService between assertions would otherwise keep the first lookup's
     * answer.
     */
    public static function forgetBranchCache(): void
    {
        self::$branchCache = [];
    }

    /**
     * The textarea's value on its way back to the `deploy_steps` column.
     *
     * `null` rather than `'[]'` when nothing parses out, mirroring
     * reference/src/routes/apps.ts:117 (`parsedSteps.length > 0 ?
     * JSON.stringify(parsedSteps) : null`). The distinction matters:
     * DeploySteps::resolve() treats NULL as "no steps configured" and would
     * treat `[]` the same, but a NULL column is what the API and the health
     * of the `source` reporting expect to see.
     */
    public static function deployStepsJson(string $text): ?string
    {
        $steps = DeploySteps::parseUiSteps($text);

        return $steps === [] ? null : json_encode($steps, JSON_THROW_ON_ERROR);
    }

    /**
     * The textarea's initial value: repo-provided steps when a `bridge.yml`
     * is what is actually in force, otherwise whatever the `deploy_steps`
     * column holds. Mirrors reference/src/routes/apps.ts:89-97.
     */
    public static function deployStepsText(App $app): string
    {
        try {
            $resolved = DeploySteps::resolve($app);

            if ($resolved['source'] === 'repo') {
                return DeploySteps::serializeUiSteps($resolved['steps']);
            }
        } catch (Throwable) {
            // A malformed deploy_steps column must not break the edit form.
            // The reference swallows resolution errors here too
            // (apps.ts:91-95) precisely so the operator can get in and fix it.
        }

        if (! $app->deploy_steps) {
            return '';
        }

        try {
            $decoded = json_decode((string) $app->deploy_steps, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? DeploySteps::serializeUiSteps($decoded) : '';
        } catch (Throwable) {
            return '';
        }
    }
}
