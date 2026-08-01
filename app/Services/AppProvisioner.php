<?php

namespace App\Services;

use App\Exceptions\CloneFailed;
use App\Models\App;
use App\Services\Process\ProcessRunner;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * The filesystem/git side effects of an app's lifecycle, ported from
 * reference/src/routes/apps.ts and reference/src/validators/appValidators.ts.
 *
 * These live in a service rather than inline in the Filament pages for two
 * reasons: the create page and the delete action are two separate callers of
 * the same rules, and every message here is parity-critical and needs to be
 * pinned by a unit test that does not stand up a Livewire component.
 *
 * Real directories, not a filesystem abstraction. The paths involved are
 * absolute host paths outside any configured Laravel disk (they must resolve
 * identically inside and outside the container — see the plan's Phase 7
 * volume note), and Phase 2 already set the precedent: DeploySteps and
 * PortBindings read `bridge.yml`/`docker-compose.yml` with plain file_exists()
 * and are tested against real temp directories. Tests here do the same.
 */
final class AppProvisioner
{
    public function __construct(
        private readonly GitService $git,
        private readonly ProcessRunner $runner,
        private readonly Filesystem $files,
    ) {}

    /**
     * Join a user-supplied relative segment onto the configured repos path.
     *
     * config('bridge.repos_path') is already trailing-slash-normalised (see
     * config/bridge.php — it is the ONE place that normalisation happens), so
     * this is a plain join, mirroring
     * reference/src/validators/appValidators.ts:12-14.
     */
    public static function fullPath(string $relative): string
    {
        return config('bridge.repos_path').'/'.ltrim(trim($relative), '/');
    }

    /**
     * The directory-state rules from validateStore(), in the reference's own
     * order. Returns the exact user-facing message, or null when the path is
     * acceptable.
     *
     * The `skip_clone` toggle flips which rule applies, and the two branches
     * are opposites: importing an existing checkout REQUIRES the directory to
     * be there (and to be a git repo), cloning REQUIRES it not to be.
     *
     * The "already uses this path" check is first and applies to both
     * branches. It compares the FULL path because that is what the `apps.path`
     * column stores — Phase 1 added a real unique index on it, so this check
     * is now a friendly message in front of a constraint rather than the only
     * thing enforcing uniqueness (reference/src/validators/appValidators.ts:42
     * was application-code-only).
     */
    public function validateNewPath(string $fullPath, bool $skipClone): ?string
    {
        if (App::query()->where('path', $fullPath)->exists()) {
            return 'An app already uses this path.';
        }

        if ($skipClone) {
            if (! $this->files->isDirectory($fullPath)) {
                return 'Directory does not exist at this path.';
            }

            if (! $this->files->exists($fullPath.'/.git')) {
                return 'Directory exists but is not a git repository.';
            }

            return null;
        }

        if ($this->files->exists($fullPath)) {
            return 'Directory already exists on disk.';
        }

        return null;
    }

    /**
     * Clone (unless importing) and seed `.env`, in that order — the seeding
     * step depends on the clone having produced `.env.example`.
     *
     * @throws CloneFailed when the clone itself fails; the message is already
     *                     the user-facing `Clone failed: …` string.
     */
    public function provision(string $fullPath, string $repoUrl, string $branch, bool $skipClone): void
    {
        if (! $skipClone) {
            try {
                $this->git->clone($repoUrl, $fullPath, $branch);
            } catch (Throwable $e) {
                throw new CloneFailed('Clone failed: '.$e->getMessage(), previous: $e);
            }
        }

        $this->seedEnvFile($fullPath);
    }

    /**
     * Copy `.env.example` to `.env` — but only when `.env` is absent.
     *
     * Never overwrites: a re-import of a directory an operator has already
     * configured must keep their real values
     * (reference/tests/Feature/appCrud.test.ts's "does not overwrite existing
     * .env" case). Ported from reference/src/routes/apps.ts:65-69.
     */
    public function seedEnvFile(string $fullPath): void
    {
        $example = $fullPath.'/.env.example';
        $env = $fullPath.'/.env';

        if ($this->files->exists($example) && ! $this->files->exists($env)) {
            $this->files->copy($example, $env);
        }
    }

    /**
     * Current contents of the app's `.env`, or '' when there is no file.
     *
     * Ported from reference/src/routes/apps.ts:147-157, which renders '' for
     * a missing file rather than erroring — a freshly imported checkout with
     * no `.env.example` legitimately has none yet, and the editor is how the
     * operator creates it.
     */
    public function readEnvFile(string $path): string
    {
        $envPath = $path.'/.env';

        return $this->files->exists($envPath) ? $this->files->get($envPath) : '';
    }

    public function writeEnvFile(string $path, string $content): void
    {
        $this->files->put($path.'/.env', $content);
    }

    /**
     * Bring the app's containers down, then remove its directory — in that
     * order, and both before the DB row goes away.
     *
     * Ported from reference/src/routes/apps.ts:132-145. Every step is
     * best-effort: the reference's runComposeDown() resolves on close, on
     * error, AND on its own 60-second SIGKILL timer, so a wedged or missing
     * `docker` never blocks the delete. Losing the DB row while the
     * containers keep running is the worse failure, so nothing here is
     * allowed to throw.
     *
     * The 60s bound is a WALL-CLOCK timeout (`timeout:`), not the idle
     * timeout DeployApp uses — `docker compose down` producing output every
     * 59 seconds forever must still be killed, unlike a legitimately long
     * build.
     */
    public function destroy(string $path): void
    {
        if (! $this->files->isDirectory($path)) {
            return;
        }

        $composeFile = $path.'/docker-compose.yml';

        if ($this->files->exists($composeFile)) {
            try {
                $this->runner->run(
                    ['docker', 'compose', '-f', $composeFile, 'down'],
                    $path,
                    [],
                    timeout: 60.0,
                );
            } catch (Throwable) {
                // Best-effort, exactly like the reference's proc.on('error').
            }
        }

        $this->files->deleteDirectory($path);
    }
}
