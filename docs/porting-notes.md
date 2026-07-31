# Laravel + Filament Port — Working Notes

Living notes for the Express → Laravel rebuild. Read this before starting a
phase. Add to it whenever you learn something a later phase would otherwise
have to re-derive.

## Resuming work

| What | Where |
|---|---|
| Phase plan | `~/.claude/plans/sorted-marinating-goose.md` |
| Phase tracking | The task list — each task carries its own QC notes |
| Specification | `reference/` — the original Express app, kept until Phase 8 |
| Branch | `laravel-port`, in a worktree at `../the-bridge-laravel` |

`reference/` is the source of truth for behaviour. When a requirement is
ambiguous, read the TypeScript rather than guessing. Do not modify anything
under it.

## Versions — read this first

**Laravel 13.23, Filament 5.7.** Both are newer than most model training data,
and Filament v5 moved substantially from v3/v4 (schemas, forms, tables and
actions all changed namespaces).

**Do not write Filament or Laravel code from memory.** Verify APIs against the
installed source in `vendor/` — class files, method signatures, PHPDoc. Code
written from v3 recall will not run.

## Filament 5 — verified findings

Each of these was confirmed by reading `vendor/filament/`, not assumed.

- **Panel path defaults to `''`, not `admin`.** `HasRoutes.php:43` is
  `protected string $path = '';`. The familiar `/admin` came from the
  `make:filament-panel` stub, never from the framework. Our panel is mounted at
  root deliberately, so resource URLs land on the original app's paths.
- **`Color::hex(string): array`** (`Filament\Support\Colors\Color`) expands one
  hex into a full Tailwind-style shade palette. That is what `->colors([...])`
  expects.
- **Dark mode is on by default** — `HasDarkMode::$hasDarkMode = true`. Calling
  `->darkMode()` is documentation, not activation.
- **`->brandLogo()`** accepts `string|Htmlable|Closure|null`. A plain string
  renders as `<img src="...">` (see
  `filament/resources/views/components/logo.blade.php`). No special logo object.
- **`->viteTheme(string)`** (trait `HasTheme`) is the v5 custom-theme hook.
- **`make:filament-user`** supports `--name --email --password`, so it works
  without a TTY.
- **Users need `Filament\Models\Contracts\FilamentUser`** to access a panel.
  `App\Models\User::canAccessPanel()` currently returns `true` unconditionally —
  fine for a single-user tool, but it is why a stray seeded account is a full
  admin.

### Custom theme setup that actually works

There is **no `tailwind.config.js`** — Tailwind v4 is CSS-first. Filament's own
`theme.css` does `@import 'tailwindcss' source(none)`, and consumer themes
import it and add `@source` directives.

1. `resources/css/filament/admin/theme.css` imports
   `../../../../vendor/filament/filament/resources/css/theme.css` plus `@source`
   globs for `app/Filament/**/*` and `resources/views/filament/**/*`.
2. Add that path to the `laravel({ input: [...] })` array in `vite.config.js`.
3. `->viteTheme('resources/css/filament/admin/theme.css')` on the panel.
4. `npm install && npm run build`.

## Laravel 13 — verified findings

- **`VerifyCsrfToken` is now `PreventRequestForgery`**
  (`Illuminate\Foundation\Http\Middleware\PreventRequestForgery`). The `$except`
  array pattern from older docs does not apply.
- **`RouteServiceProvider` defers `routes/web.php`** via `$this->booted()`
  (`RouteServiceProvider.php:51-64`), so it loads *after* all providers boot —
  including Filament's route registration.

## Project gotchas

### Route precedence is inverted from intuition

`routes/web.php` registers **after** the panel, and `RouteCollection` is keyed by
method+URI, so a colliding route **replaces** the panel's and **erases its
name**. Adding `Route::get('/login')` deletes `filament.admin.auth.login` from
the route table entirely; the panel then throws `RouteNotFoundException` on its
next anonymous redirect. No boot-time or build-time warning.

Never reuse a panel URI+method in `web.php`. The full explanation is in the file.

Verified safe: `POST /apps/{id}/webhook` and `GET /api/*` do not collide, because
Filament resource routes bind `{record}` — `apps/{record}` and
`apps/{id}/webhook` are distinct keys.

### The webhook cannot live in the `web` group

Everything in `routes/web.php` gets `PreventRequestForgery`. The GitHub webhook
is public, machine-to-machine, and carries no CSRF token — registered there it
returns **419 on every push** and webhook deploys silently stop working.

There is also no `api:` argument in `bootstrap/app.php`'s `withRouting()` yet, so
`routes/api.php` is never loaded: no `/api` prefix, no `throttle:api`, no
stateless handling. Phase 5 must add it. The tell that this is an oversight —
`bootstrap/app.php` already declares `shouldRenderJsonWhen($request->is('api/*'))`
for a namespace that cannot currently exist.

### API token resolution is two-layer, and fails closed

`config('bridge.api_token')` is the **env layer only**. The reference resolves:
`BRIDGE_API_TOKEN` if non-empty, otherwise the `api_token` row in the `settings`
table (`reference/src/middleware/apiAuth.ts:5-10`) — which is how operators
actually set it, through the Settings screen.

A DB lookup cannot live in a config file (config is cached and resolved before
the database is meaningfully available), so Phase 5 needs a resolver service.

When neither source yields a token the API returns **503 "API token not
configured"** to every caller. Empty does **not** mean public, and must never be
made to.

### Paths

`BRIDGE_REPOS_PATH` is a rename of the reference's `REPOS_PATH`. Intentional —
but an operator upgrading in place keeps the old variable set, the app never
reads it, config falls back to the literal `/repos`, and every clone target
breaks with a misleading "path not found". Phase 7 should document the migration
and consider failing loudly when `REPOS_PATH` is set but `BRIDGE_REPOS_PATH` is
not.

In the container the host directory is bind-mounted at the **same absolute
path**, so paths stored in the database resolve identically inside and out. This
is non-negotiable.

Locally, `/repos` cannot be created on macOS — the root volume is read-only under
SIP. `.env` points at directories inside the project instead. Tests must read
from config rather than hardcoding `/repos`.

The reference strips a trailing slash before use
(`reference/src/validators/appValidators.ts:9`); `config/bridge.php` does not, so
Phase 2 must normalise or `BRIDGE_REPOS_PATH=/repos/` yields doubled separators.

### LCARS

`public/lcars.css` is the original design system and is **not** to be rewritten.
Phase 0 mapped its four brand colours onto the panel palette and copied
`.lcars-log` into the Filament theme for the Phase 6 deploy log viewer.

One nuance: the theme hardcodes the hex values where `lcars.css:254-255` uses
`var(--color-log-bg)` / `var(--color-log-text)`. Equivalent today only because
those two variables are defined once in `:root` and are *not* overridden by the
`[data-theme="dark"]` or `prefers-color-scheme` blocks. If anyone adds a dark
override there, the theme copy stops tracking it.

### Seeding

`DatabaseSeeder` creates the initial panel admin from `BRIDGE_ADMIN_EMAIL` and
`BRIDGE_ADMIN_PASSWORD`. There is deliberately **no default password** — the
seeder is expected to run unattended from the container entrypoint, and a
baked-in credential would be a known admin login on a host that executes
`docker compose`. Unset config makes it warn and skip.

It uses `firstOrCreate`, so a password changed through the UI is not reverted on
the next boot. The panel has no public registration, so operators who skip these
variables reach a login screen with no account.

### Ignored paths worth knowing about

`data/` and `repos/` are gitignored for real reasons: `data/ssh/id_rsa` is the
private deploy key for every managed private repo, and `repos/` holds the full
working tree of every deployed application including their own `.env` files.

Filament's published assets (`public/{css,js,fonts}/filament`) are also ignored —
`composer`'s `post-autoload-dump` runs `filament:upgrade` and regenerates them,
so committing them only produces binary churn. The Docker build must run
`composer install` (or `filament:assets`) rather than assuming they are present.

## Phase 1 — data layer

### Schema: folded ALTERs, dropped `jobs`, real unique index

`reference/src/db.ts` `bootstrapSchema()` builds the schema in two passes: a
`CREATE TABLE` block, then a list of best-effort `ALTER TABLE ... ADD COLUMN`
statements wrapped in try/catch (SQLite has no `ADD COLUMN IF NOT EXISTS`).
Those added columns — `apps.health_url`, `apps.health_check_interval`,
`apps.webhook_secret`, `apps.deploy_steps`, `deployments.commit_sha`,
`deployments.commit_message`, `deployments.rollback_sha` — are folded
straight into the initial `create_*_table` migrations here. There is no
equivalent try/catch pattern in this port; if a column needs to change later
it gets its own migration, the normal Laravel way.

The reference's custom `jobs` table (a hand-rolled queue: `queue`, `payload`,
`attempts`, `reserved_at`, `available_at`, `created_at`) is **dropped**.
Laravel's own `jobs`/`job_batches`/`failed_jobs` tables (already present from
the Phase 0 skeleton, all three in
`0001_01_01_000002_create_jobs_table.php`) replace it — we use the database
queue driver, not a bespoke one. `QUEUE_CONNECTION=database` in `.env`.

`apps.path` now has a real unique index (`$table->string('path')->unique()`),
where the reference only validated uniqueness in application code
(`reference/src/validators/appValidators.ts`). This is called out explicitly
because Phase 4's app form must catch the resulting `QueryException` /
`UniqueConstraintViolationException` and turn it into the message `An app
already uses this path.` — a raw SQLite constraint message must never reach
the UI.

### SQLite foreign keys are on, and cascade delete is proven, not assumed

`config/database.php`'s `sqlite` connection sets `'foreign_key_constraints'
=> env('DB_FOREIGN_KEYS', true)`, and
`Illuminate\Database\Connectors\SQLiteConnector` runs `pragma foreign_keys =
1` at connection time as a result. This was verified by reading the
connector source, not assumed from docs. `tests/Feature/MigrationTest.php::test_sqlite_foreign_key_enforcement_is_enabled`
asserts `PRAGMA foreign_keys` is `1` in the test connection, and
`test_deleting_an_app_cascades_to_deployments_and_health_checks` (plus the
equivalent case in `DeploymentModelTest`/`HealthCheckModelTest`) proves rows
actually disappear — `deployments.app_id` and `health_checks.app_id` both use
`->constrained('apps')->cascadeOnDelete()`.

### Backed enums, and how they interact with the query builder

`app/Enums/{AppStatus,DeploymentStatus,HealthStatus}.php` are plain PHP
backed string enums (`enum AppStatus: string { case Idle = 'idle'; ... }`),
cast on the models via the `protected function casts(): array` method (same
pattern already used on `App\Models\User`), not the `Attributes\Fillable`-style
class attributes — there's no cast attribute in this Laravel version, so the
method form is still how you declare casts.

Worth knowing for Phase 2+: Laravel's query builder unwraps `BackedEnum`
values automatically in bindings — `Illuminate\Database\Query\Builder::castBinding()`
calls the global `enum_value()` helper, so `App::query()->where('status',
AppStatus::Deploying)->update(['status' => AppStatus::Failed])` works without
manually pulling `->value`. This does **not** apply to raw `DB::update()`
with a hand-built SQL string (used in `Deployment::appendLog()`) — there,
values passed in the bindings array are bound as-is by PDO, so anything that
isn't already a scalar (e.g. a `Carbon` instance) needs an explicit
`->toDateTimeString()`.

### `deploy_steps` serialisation decision — revised after QC

**Final decision: `deploy_steps` is a plain string attribute on
`App\Models\App` — no `array` cast.** An earlier version of this port cast
it to `array` and reasoned the round-trip was lossless; that reasoning was
incomplete and the decision has been reversed. The corrected reasoning,
from reading `reference/src/services/deploySteps.ts` and
`reference/src/services/deploySteps.test.ts`:

- `reference/src/services/deploySteps.test.ts:120` has a case, **one of the
  115 parity cases**, requiring `resolveDeploySteps()` to *throw*
  `post-deploy: deploy_steps JSON parse error` when `apps.deploy_steps`
  contains malformed JSON. That's a deploy-aborting error in the reference —
  the operator gets a diagnostic instead of a silent no-op.
- `Illuminate\Database\Eloquent\Casts\Json::decode()` is a thin wrapper over
  plain `json_decode()` with no `JSON_THROW_ON_ERROR`. Under an `array` cast,
  malformed JSON in the column decodes to `null` — **silently** — which is
  byte-for-byte indistinguishable from "no steps configured" (the same
  `null` a genuinely empty column produces). The malformed-JSON branch
  becomes unreachable: Phase 2's ported `DeploySteps` service would never
  see the bad string to throw on, and would run *zero* post-deploy steps
  where the reference aborts the deploy. That's a parity break, not a
  cosmetic difference.
- The "cosmetic slash-escaping" framing in the original version of this note
  was also incomplete: the real risk of a cast at this boundary isn't
  encoding cosmetics, it's that **the cast swallows the one error case the
  parity suite explicitly requires to surface**.
- The originally-suggested escape hatch — recover the raw JSON via
  `$app->getRawOriginal('deploy_steps')` if Phase 2 ever needed it — is
  **unreliable** and should not be used: after `$app->deploy_steps = [...]`
  (an in-memory assignment, not yet saved), `getRawOriginal()` still returns
  the *previous* persisted value, not the pending one. There is no cast
  configuration that makes "read back exactly what's about to be saved"
  safe; not casting at all sidesteps the problem entirely.

Net effect: `App::casts()` no longer lists `deploy_steps`. Phase 2's
`DeploySteps` service must do its own `json_decode($app->deploy_steps,
true)` with an explicit `json_last_error() !== JSON_ERROR_NONE` (or
try/catch around a `JSON_THROW_ON_ERROR` decode) check and throw on failure,
mirroring the reference's `try { JSON.parse(...) } catch` exactly — the
model should not do this work on the service's behalf.

### `health_check_interval` — kept, unused until Phase 2

Column is present (`apps.health_check_interval`, integer, default 60) and
cast to `'integer'` on the model, per the explicit instruction to keep it
even though the reference never reads it. **Phase 2's `HealthPoller` is
expected to use this as the per-app polling interval in seconds** (falling
back to the DB default of 60 when not set). Nothing in Phase 1 reads it
either — it's just carried forward and proven to round-trip via
`AppModelTest`/`MigrationTest`.

### `Setting` model: `getValue`/`setValue`, not `get`/`set`

`reference/src/models/setting.ts` exports free functions `get(key)` and
`set(key, value)`. The Eloquent model uses `Setting::getValue()` /
`Setting::setValue()` instead of `get`/`set` — nothing stops a model from
defining static methods literally named `get`/`set` (an explicitly defined
static method wins over `__callStatic`'s forwarding-to-a-new-query
behaviour), but the reference's `get` reads confusingly next to Eloquent's
own query-builder `get()` semantics, so the port renames for clarity. `key`
is the primary key (`$primaryKey = 'key'`, `$keyType = 'string'`,
`$incrementing = false`), and the table has no timestamps.

### `appendLog()` must not discard the caller's unsaved state

`Deployment::appendLog()` originally ended with `return $this->refresh();`.
QC caught this: `refresh()` reloads **every** attribute from the database,
which silently wipes any dirty (assigned-but-unsaved) attribute on the same
instance. Phase 3 holds one `$deployment` throughout an entire deploy and
interleaves log writes with status/SHA assignment — the exact pattern is
`$d->commit_sha = $sha; $d->appendLog($out); $d->save();`. With `refresh()`,
that `save()` persists whatever was in the DB before the assignment (`status`
reverts, `commit_sha` reverts to `null`), and a `null commit_sha` on a
`success` deployment is exactly what `findLastSuccessful()`'s
`commit_sha IS NOT NULL` filter screens on — the deploy would land as
`success` but be silently un-rollbackable.

The fix pulls back only the `log` column after the raw `DB::update()` and
sets it directly via `setAttribute()` + `syncOriginalAttribute()`, leaving
every other attribute — saved or dirty — untouched. Verified by mutation:
reinstating `return $this->refresh();` makes
`DeploymentModelTest::test_append_log_preserves_other_dirty_attributes` fail
(`status` comes back `running` instead of the assigned `success`); the fix
restores it to green.

**The `syncOriginalAttribute('log')` call is load-bearing, not tidiness —
this was not obvious from reading the diff.** Without it, `setAttribute()`
alone leaves `log` marked dirty (its new value differs from what `$original`
recorded at hydration), even though the DB now genuinely holds that value.
A later `$dep->save()` on the same instance would then be a **read-modify-
write**: Eloquent writes this instance's in-memory `log` back to the row,
clobbering anything a concurrent writer appended to the same row in the
interim. That's precisely the hazard the SQL-level
`COALESCE(log, '') || ?` concatenation in `appendLog()` exists to avoid
(reference/src/models/deployment.ts:64 uses the same trick) — and Phase 3
(a worker streaming build output chunk by chunk) plus Phase 5 (the API
touching the same deployment row) are exactly the concurrent writers this
protects against. `syncOriginalAttribute()` closes that gap by telling
Eloquent "the in-memory value and the DB value now agree," so a subsequent
`save()` doesn't re-send `log` at all.

The line reads as inert — deleting it left the full 47/47 suite green until
`DeploymentModelTest::test_append_log_does_not_clobber_a_concurrent_writers_chunk_on_next_save`
was added specifically to pin it (a raw `DB::update()` between `appendLog()`
and `save()` simulates the concurrent writer; no real concurrency needed).
Verified by mutation: deleting `syncOriginalAttribute('log')` makes that test
fail — `log` ends up `"worker chunk\n"`, silently dropping the concurrent
writer's `"concurrent\n"` — and restoring the line brings it back to green.
If this line is ever "cleaned up" as a no-op, that test is the guard rail
that should catch it.

Two related costs worth knowing about, both correct behaviour, not bugs:

- **`appendLog()` costs 2 queries per chunk** (the `UPDATE`, then a `SELECT
  log` to read the fresh value back) where the reference does 1. Not a
  regression — the version this replaced (`refresh()`) also read, just more
  broadly — and reading back is the safer choice: reconstructing the new
  `log` value in PHP (e.g. concatenating the chunk onto the pre-call value)
  would silently reintroduce the exact read-modify-write drift this method
  exists to prevent. Phase 3, which will call this once per streamed output
  chunk, should size its expectations around 2 queries/chunk.
- **A direct `$d->log = '...'` assignment made before calling `appendLog()`
  is silently discarded.** `appendLog()`'s own `setAttribute('log', ...)`
  overwrites whatever was there, dirty or not, with the freshly-read DB
  value. This is correct — `appendLog()` is the sanctioned way to write
  `log`, and the whole point is that it never trusts an in-memory copy — but
  it means Phase 3 must not assign `log` directly and expect it to survive;
  route all log writes through `appendLog()`.

### Multi-app scoping filters need multi-app tests, proven by mutation

QC found that `findLastSuccessful()`'s `app_id` filter and both `app_id`
filters inside `HealthCheck::record()`'s prune (the outer `DELETE` scope and
the inner keep-set subquery) were structurally untestable by the original
single-app test suite: `RefreshDatabase` gives every test a clean DB, and a
test that only ever creates one app cannot distinguish "scoped to this app"
from "scoped to everything, which happens to be just this app." Deleting any
of those three filters left the full suite green.

Fixed by adding tests that create a second app and check it is unaffected
(or, for the `record()` inner filter specifically, that both apps keep
producing fresh/high ids while both are actively above the 20-row
threshold — see the comment on
`HealthCheckModelTest::test_record_prune_ranks_within_the_owning_app_even_when_interleaved`
for why a simple "one app's rows all predate the other's" ordering isn't
enough to catch a bug in *that particular* filter). All three were verified
by mutation: temporarily deleting the filter in question, confirming the
relevant new test fails, then restoring it.

This is a standing lesson for later phases too: any query that's supposed to
be scoped per-app (or per-anything) needs a test with at least two of that
thing, not one — a single-instance test proves the query runs, not that it's
scoped.

### `consecutiveFailures()` — deliberately not ported

`reference/src/models/healthCheck.ts` exports `consecutiveFailures(appId)`,
counting trailing non-`up` health checks. Confirmed via `find_references`-style
search across `reference/src/` that nothing calls it — no route, no service,
no scheduled job. Per the Phase 1 brief it is not ported. If a later phase
needs "how many consecutive failures" (e.g. an alerting threshold), re-add it
then rather than carrying dead code forward now.

### Test suite mapping

`php artisan test` — **48 tests, 127 assertions, all green** (PHPUnit, not
Pest — confirmed from `composer.json`'s `phpunit/phpunit` requirement and the
plain-PHPUnit style of `tests/Unit/ExampleTest.php`/`tests/Feature/ExampleTest.php`
already in the skeleton).

| Reference suite | Ported to | Notes |
|---|---|---|
| `reference/tests/Unit/models.test.ts` | `tests/Unit/AppModelTest.php`, `tests/Unit/DeploymentModelTest.php` | Case for case, plus explicit `findLastSuccessful()` coverage (all four conditions individually, including a dedicated multi-app case proving the `app_id` filter — see above), an ANSI-stripping case, an `appendLog()`-preserves-dirty-attributes case, and an `appendLog()`-does-not-clobber-a-concurrent-writer case (pins `syncOriginalAttribute('log')` — see above), since the reference itself has no dedicated test for any of those. |
| `reference/tests/Unit/healthCheck.test.ts` | `tests/Unit/HealthCheckModelTest.php` | First two cases ported case for case (`findLatest`, `listRecent` truncates to 20, and now also asserts ordering explicitly). Third case (`consecutiveFailures`) intentionally **not** ported — see above. Added an explicit 20-row-prune-keeps-newest assertion, two multi-app prune-scoping cases (one straightforward, one interleaved — see above for why both are needed), and a cascade-delete case. |
| `reference/tests/Unit/enums.test.ts` | `tests/Unit/EnumsTest.php` | Both cases ported case for case; added a third (unrequested by the reference) for `HealthStatus`, since it's in this port's scope. |
| `reference/tests/Feature/migration.test.ts` | `tests/Feature/MigrationTest.php` | All three reference cases ported: `apps`/`deployments` column checks case for case, and the `jobs`-table column check **is** ported too (initially miscategorized as dropped-with-the-custom-jobs-table — corrected: Laravel's own skeleton `jobs` table happens to carry the exact same seven columns the reference asserts, so the case still applies unmodified). Added `health_checks`/`settings` column cases, a `Schema::getIndexes()`-based check that `apps.path` carries a real unique *index* (not just a constraint-violation test, which is also kept separately), the `PRAGMA foreign_keys` check, and a cascade-delete case. |
| *(not in reference)* | `tests/Feature/ResetStuckDeploymentsTest.php` | New — covers `resetStuckDeployments()`'s Laravel port, including the byte-for-byte interrupt-note string and that untouched apps/deployments are left alone. |
| *(not in reference)* | `tests/Unit/SettingModelTest.php` | New, minimal — the reference only exercises `Setting` through the Feature-level `settings.test.ts` (an HTTP test, Phase 4/5 scope), so there's no Unit-level reference case to map here. |
| `reference/src/services/deploySteps.test.ts` | `tests/Unit/Services/DeployStepsTest.php` | All 24 cases ported case for case (12 `resolveDeploySteps`, 8 `parseUiSteps`, 4 round-trip). Uses `App::factory()->make()` (unsaved) rather than persisting, since `DeploySteps::resolve()` only reads `$app->path`/`$app->deploy_steps` off the in-memory instance. |
| `reference/src/services/portBindings.test.ts` | `tests/Unit/Services/PortBindingsTest.php` | All 15 cases ported case for case, plus 17 more (32 total) added across two passes — the first pass's colon-vs-bare and bare-`$VAR` cases, then QC's B7/B8 findings (the full `?`/`:?` operator family, all 8 empty/undefined × bare/colon quadrants) and four `.env`/short-form edge cases (comment-line skipping and first-equals-only splitting, both pinned via reflection on the private `readDotenv()`; long-form `host_ip: ""`; >3-segment short-form rejection). See "PortBindings" and "QC pass" below. |
| `reference/tests/Unit/gitService.test.ts` | `tests/Unit/Services/GitServiceTest.php` | The reference's 3 cases (clone succeeds, clone throws, pull succeeds) are ported but adapted to a `FakeProcessRunner` instead of a real git remote — see "Process execution seam" below. 11 total: clone/ls-remote argv and SSH-conditional cases from the first pass, plus three comprehensive "full command sequence + cwd, for every pull() branch" tests added after QC B4/B5/B6 found most of `pull()`'s sub-commands (including the branch-detection `rev-parse` call) were unpinned. |
| `reference/tests/Unit/healthPoller.test.ts` | `tests/Unit/Services/HealthPollerTest.php` | The reference's 3 cases (records up/down, skips apps without `health_url`) are ported using `Http::fake()` in place of a mocked global `fetch`. 11 total: the per-app-interval skip/poll pair from the first pass, plus QC B1/B2/B3 additions — an empty-string-`health_url`-doesn't-abort-the-pass case, an interval-genuinely-diverges-from-default case, and a 10s-request-timeout case (using `Http::fake()`'s two-argument stub form to inspect Guzzle's `$options['timeout']`, since the recorded `Request` object doesn't carry it). |
| `reference/tests/Unit/slackNotifier.test.ts` | `tests/Unit/Services/SlackNotifierTest.php` | The reference's 2 cases (no-op without a webhook URL, POSTs when configured) are ported. 17 total: emoji/color/duration/log-tail/context-link/relation cases from the first pass, plus QC additions pinning the `>=60` duration boundary exactly (59/60/61s, not just two widely-separated durations), the `'App'` name fallback, the `http://localhost:3000` base-URL fallback, and `trim()` on the log tail. |
| *(not in reference)* | `tests/Unit/Services/ContainerStatusTest.php` | New — `containerStatus.ts` has no dedicated `.test.ts` in the reference. Covers NDJSON parsing, the empty-object-line edge case (see "ContainerStatus" below), non-zero exit (with non-empty stdout, after QC found the original empty-stdout version didn't actually pin the exit-code guard), timeout/malformed-JSON-all-return-`[]`, and the literal `docker compose ps --format json` argv with its 8s timeout. |
| *(not in reference)* | `tests/Unit/Services/Process/SymfonyProcessRunnerTest.php` | New, added after QC B9 — the real `ProcessRunner` implementation had zero coverage. 4 real-process integration tests (shells out to `bash`/`sleep`/`echo`): incremental output streaming, idle timeout killing a silent process (and returning a well-formed `timedOut` `ProcessResult`, not throwing), idle timeout surviving repeated output, and — via `ReflectionMethod` on the private `buildProcess()` — that `null` timeouts are not silently defaulted back to Symfony's own 60s constructor default. |
| *(not in reference)* | `tests/Unit/ConfigReposPathTest.php` | New, added after QC B10 — `config/bridge.php`'s trailing-slash normalisation had zero coverage. Re-`require`s the config file after mutating the env var (`putenv()` + `$_ENV`/`$_SERVER`) to test the real file's regex against all four cases the reference's `.replace(/\/$/, '')` handles. |

### Things later phases would otherwise re-derive

Flagged during Phase 1 QC — none of these need action now, but each is a
trap for the phase named:

- **`app_name` is an API contract field, not just a convenience join.**
  `reference/src/models/deployment.ts:31-33` flattens `app_name`, `app_path`,
  `app_branch`, `app_repo_url`, `app_status` onto the deployment row via a
  SQL `JOIN`. Consumers depend on the literal flattened shape:
  `reference/src/routes/api.ts:86` (JSON key `app_name`),
  `reference/src/openapi.ts:34`, `reference/src/services/slackNotifier.ts:31`,
  and `reference/src/views/deployments/show.ejs:4`. The `Deployment::app()`
  `belongsTo` relation here is architecturally the right call, but it means
  Phase 5 must still *emit* a literal `app_name` key (not just nest an
  `app: {...}` object) wherever the reference's API/OpenAPI contract expects
  it, Phase 2's ported `SlackNotifier` must not assume `$deployment->app_name`
  exists on a bare model (it needs `$deployment->app->name`, or eager-loaded
  access), and any deployments list Phase 5/6 builds needs `->with('app')` or
  it's an N+1 query per row.
- **Three datetime wire formats now coexist — pick one before Phase 5 ships
  an API.** The reference stored ISO-8601 with milliseconds
  (`2026-07-30T21:00:00.000Z`). Laravel stores `Y-m-d H:i:s` in the DB and
  serialises `Carbon` instances to JSON with six fractional digits by
  default. `health_checks.checked_at` comes from SQLite's `CURRENT_TIMESTAMP`
  — second resolution, no `T`/`Z`. Nothing in Phase 1 needs these to agree,
  but Phase 5's API responses do.
- **Log offsets: bytes vs. UTF-16 code units.** Stripping ANSI escapes and
  `\r` on write (not read) is correct and was verified byte-exact against the
  reference regex over 4,010 fuzz inputs. But *offsets* into the stored log
  are a different concern: PHP's `strlen()`/`substr()` operate on bytes,
  while the reference's offsets (JS string indexing) are UTF-16 code units.
  These diverge on any non-ASCII build output (`→`, box-drawing characters
  from `npm`/`docker` output are common culprits). Phase 5/6, which hands out
  a log offset for polling and accepts it back on the next request, must pick
  one unit and be internally consistent — it does not need to match the
  reference's unit, just itself.
- **`apps.path`'s unique index reaches Phase 5, not just Phase 4.** A
  Filament form can catch this with `->unique(ignoreRecord: true)` plus
  `->validationMessages(['unique' => 'An app already uses this path.'])`, but
  that only covers the form. The API and webhook app-creation endpoints (see
  `reference/src/routes/api.ts`) bypass the form entirely and need their own
  explicit `catch` for the constraint violation
  (`Illuminate\Database\UniqueConstraintViolationException` or a
  `QueryException` inspected for the SQLite unique-constraint error code) so
  a raw SQLite message never reaches a JSON error body.
- **`HealthCheck::record()`'s return value has `checked_at` null in
  memory.** The column default (`useCurrent()`) is applied by SQLite at
  insert time and is never read back — Eloquent's `create()` doesn't refetch
  DB-computed defaults after an insert. If Phase 2's `HealthPoller` needs the
  actual timestamp off the object `record()` hands back (rather than just
  the app/status/etc. it explicitly passed in), it must `->refresh()` first.

## Phase 2 — Services

All six services from `reference/src/services/` are ported to `app/Services/`:
`GitService`, `PortBindings`, `ContainerStatus`, `DeploySteps`, `HealthPoller`,
`SlackNotifier`. None of them touch HTTP, Filament, or the queue — Phase 3
(the deploy pipeline) is the first consumer.

`php artisan test` — **162 tests, 330 assertions, all green** (Phase 1's 48
tests/127 assertions plus 114 new tests/203 new assertions from this phase,
across two QC rounds).

### QC pass — 10 blockers, 1 real bug

An adversarial, mutation-based QC pass (56 mutations run against the
implementation) came back NOT CLEAN on the first submission. The source
parity itself was confirmed good — every git argv, all eight interpolation
operator forms, the NDJSON decode, DeploySteps' resolution priority, and the
Slack payload were compared line-by-line against the TypeScript and only one
divergence was found. The other nine blockers were Phase 1's own recurring
pattern, this time in Phase 2: **code correct, coverage absent**, each
proven by a mutation that survived (changed the code, full suite stayed
green). All ten are fixed below, each verified by running the stated
mutation, confirming the new test fails, then restoring and confirming
green again — the same discipline Phase 1 used for `syncOriginalAttribute()`
and the multi-app scoping filters.

- **B1 (real bug) — empty-string `health_url` aborted the whole poll pass.**
  `HealthPoller::pollDue()` used `whereNotNull('health_url')`, which admits
  `''`. `check()` then threw `InvalidArgumentException` for that app,
  uncaught, killing the `->each()` loop — a second, healthy app in the same
  pass never got polled. Filament text inputs persist `''` by default
  (Phase 4 will make this reachable), and under the self-rescheduling-job
  design (see below) an uncaught throw is not a degraded pass, it is a
  **permanent outage**: the job's `handle()` never reaches the
  `self::dispatch()->delay(...)` requeue call. Fixed two ways, deliberately
  redundant: the query now excludes `''` explicitly
  (`->where('health_url', '!=', '')`), matching the reference's falsy guard
  (`if (!app.health_url) continue;`, not a null check), AND `pollDue()`
  wraps each app's `check()` call in its own try/catch so one app's failure
  — this one or any other — can never abort the pass for the rest. For this
  specific bug the two defenses are behaviourally equivalent (either alone
  prevents the crash), confirmed by mutation: removing just the query filter
  left the suite green because the try/catch backstop absorbed it; removing
  **both** together reproduced the original crash exactly
  (`HealthPoller::check() requires an app with a health_url` escaping
  `pollDue()`). Kept both anyway — the try/catch is real hardening against
  a different failure mode (e.g. a DB hiccup inside `HealthCheck::record()`)
  that the query filter can't cover. **This claim was initially made but
  untested — see B1-residual below, which is where it actually got pinned.**
- **B1-residual — the try/catch was an untested unbounded swallow, and it had
  degraded a parity case.** Re-QC found the redundancy framing above was
  accepted, but the actual problem was worse than "one of two lines is
  unpinned": with BOTH the query filter's `whereNotNull` and `!= ''` removed
  together, `pollDue()` iterates a null-`health_url` app, `check()` throws
  `InvalidArgumentException`, and the catch-all silently absorbed it — so
  `test_poll_due_skips_apps_without_health_url`
  (`reference/tests/Unit/healthPoller.test.ts:26`'s port, whose entire
  assertion is "fetch was not called") passed whether the app was correctly
  filtered out **or** admitted and swallowed. Nothing distinguished
  *skipped* from *attempted-and-swallowed*. Separately, the catch's own
  containment claim — that it guards against failures unrelated to
  `health_url` — had no test proving it actually contains anything; a
  mutation deleting the generic `catch (Throwable)` entirely still passed
  every other test.

  Fixed with two changes, not just two tests:
  1. `pollDue()`'s catch now has two arms: `catch (InvalidArgumentException
     $e) { throw $e; }` first, then `catch (Throwable) {}`. An
     `InvalidArgumentException` from `check()` means the query filter is
     broken (an app with no `health_url` shouldn't have reached `check()`
     at all) and is deliberately let through rather than absorbed — a
     broken filter now fails loudly instead of masquerading as "correctly
     skipped". This is what makes the existing
     `test_poll_due_skips_apps_without_health_url` (and its empty-string
     counterpart) genuinely discriminating again: verified by mutation,
     removing both query filters together now errors with
     `HealthPoller::check() requires an app with a health_url` instead of
     passing 11/11.
  2. Added `test_poll_due_contains_an_unrelated_per_app_failure_and_still_polls_the_rest`,
     which forces a real, `health_url`-unrelated failure (deletes the app
     row out from under `HealthPoller` from inside its own faked HTTP
     response, so `HealthCheck::record()`'s insert hits the
     `health_checks.app_id` foreign-key constraint) and asserts a
     second, healthy app is still polled afterward. Verified by mutation:
     deleting the generic `catch (Throwable) {}` arm (keeping only the
     `InvalidArgumentException` one) now fails this test with the FK
     `QueryException` escaping `pollDue()`, closing the "B1's containment
     claim is asserted, not proven" gap.
- **B2 — `health_check_interval` wasn't actually pinned.** The original two
  paired tests (300s with a just-created check; 1s backdated 10 minutes)
  both happened to agree with the 60s default regardless of whether the
  column was read at all. Added
  `test_poll_due_honors_a_per_app_interval_that_diverges_from_the_default`
  (3600s interval, checked 120s ago — the default would say "due", honoring
  the column must say "not due"). Verified by mutation: hardcoding
  `$interval = self::DEFAULT_INTERVAL_SECONDS` in `isDue()` now fails
  exactly that one case.
- **B3 — the 10s request timeout had zero coverage.** Neither shrinking it
  to 1s nor deleting `Http::timeout(...)` entirely failed anything.
  `Http::fake()`'s recorded `Request` object does not carry the raw Guzzle
  request options (`timeout` isn't part of the PSR request it wraps) — the
  fix uses `Http::fake(function ($request, $options) {...})`'s two-argument
  form instead of the usual single-argument stub closure, capturing
  `$options['timeout']` directly. `PendingRequest::buildStubHandler()`
  invokes stub callbacks with exactly `($request, $options)`, which is what
  makes this possible without any framework changes.
- **B4/B5/B6 — most of `pull()`'s five sub-commands were unpinned.** Only
  clone's argv and the checkout/`checkout -b` argv (index 4 in the old
  tests) were ever asserted. `git config safe.directory *`'s argument,
  every argument on fetch/rev-parse/pull, and the `cwd` on all five
  sub-commands could be mutated freely with a green suite — including
  `rev-parse --abbrev-ref HEAD` → `rev-parse HEAD`, which silently turns
  branch detection into a SHA comparison (`$currentBranch !== $branch`
  becomes permanently true, so every pull takes the checkout path). Fixed
  by replacing the narrow single-index assertions with three tests that
  assert the FULL command sequence (all 4 or 6 argv arrays, in order) AND
  loop over every recorded call asserting `cwd === $repoPath` — one test
  per `pull()` branch (already on target branch; switching to an existing
  local branch; creating a new tracked branch). All three mutations were
  run individually and each broke a distinct, correctly-attributed
  assertion.
- **B7 — the `?`/`:?` operator family had zero coverage.**
  `reference/src/services/portBindings.ts:54` returns `''` when the
  variable isn't usable, not the text after the `?`. A mutant that returned
  the error text instead (`return $usable ? $val : $arg;`) passed the full
  suite because nothing used `?` or `:?` at all. Added 4 cases (bare `?`
  defined/undefined, colon `?` empty/non-empty); the mutation now fails
  exactly those.
- **B8 — only 3 of 8 empty/undefined × bare/colon quadrants discriminated.**
  Covered before: `:-`/empty, `:-`/undefined, `-`/empty, `:+`/empty,
  `+`/empty. Missing: `-`/undefined, `+`/undefined, `+`/defined-non-empty,
  `:+`/defined-non-empty. Added all four; a single swap-style mutation on
  the `+` branch (`return $usable ? '' : $arg;`, i.e. inverted) broke 5
  tests at once — 3 of the 4 new ones (`+`/undefined, `+`/defined-non-empty,
  `:+`/defined-non-empty; the 4th new case, `-`/undefined, uses the `-`
  operator so this particular mutation doesn't touch it) plus 2 existing
  `+`-family cases (`:+`/empty, `+`/empty) — confirming the `+` quadrant is
  now covered from multiple angles, not just individually poked.
- **B9 — `SymfonyProcessRunner` had zero tests.** The one concrete
  `ProcessRunner` that actually runs in production — every service test in
  the phase injects `FakeProcessRunner`, so nothing would notice the real
  implementation breaking. See "SymfonyProcessRunner — real integration
  tests" below.
- **R4 (found on re-QC) — a stalled process's pre-stall output was
  undetected as preserved.** The probe proving `$process->getOutput()`
  really does carry output captured before an idle-timeout kill was
  correct, but the ORIGINAL idle-timeout test used `['sleep', '5']`, which
  produces no output at all — it structurally could not observe whether
  pre-stall output survives, only that the kill itself happens.
  `SymfonyProcessRunner::run()`'s timeout branch could have been replaced
  with `new ProcessResult(1, '', '', timedOut: true)` (discarding both
  `getOutput()` and `getErrorOutput()` entirely) and 23 tests across
  `SymfonyProcessRunnerTest`, `GitServiceTest`, and `ContainerStatusTest`
  would still pass. This matters specifically for Phase 3: when a
  40-minute build hangs, what it printed before hanging IS the diagnostic
  — `reference/src/jobs/deployApp.ts:46-47` streams every chunk before
  `resolve(1)` for exactly this reason. Fixed by rewriting the test to
  print to both stdout and stderr before going silent past the idle
  window, and asserting both survive on the returned `ProcessResult`.
  Verified by mutation: discarding both `getOutput()`/`getErrorOutput()`
  fails the test; discarding just `getErrorOutput()` fails it too.
- **B10 — `config/bridge.php`'s trailing-slash normalisation had zero
  coverage.** Reverting the `preg_replace` back to plain
  `env('BRIDGE_REPOS_PATH', '/repos')` left the full 129/129 (pre-QC count)
  green, because no test exercised `config('bridge.repos_path')` directly —
  every filesystem-touching service test builds its own explicit temp path.
  `tests/Unit/ConfigReposPathTest.php` re-`require`s the config file after
  changing the env var (`putenv()` plus `$_ENV`/`$_SERVER`, all three — see
  the test's docblock for why `putenv()` alone isn't reliably picked up by
  Laravel's `env()`), pinning all four cases the reference regex handles:
  `/repos/`→`/repos`, `/repos//`→`/repos/`, `/`→`''`, `//`→`/`.

### Process execution seam — how Phase 3 should use it

`app/Services/Process/` holds three pieces:

- `ProcessRunner` (interface) — `run(array $command, ?string $cwd, array
  $env, ?float $timeout, ?float $idleTimeout, ?callable $onOutput):
  ProcessResult`. Every parameter Phase 3 needs is already on this
  signature, including `$idleTimeout` and the streaming `$onOutput`
  callback — Phase 2's own callers (`GitService`, `ContainerStatus`) only
  ever pass `$timeout`, but the interface does not bake in an
  assumption that would block Phase 3's "40-minute build that keeps
  printing output" requirement. Phase 3 should call `run()` with
  `idleTimeout: ...` and `timeout: null`, not the other way around.
- `SymfonyProcessRunner` (concrete) — thin wrapper over
  `Symfony\Component\Process\Process`, bound as the default `ProcessRunner`
  in `AppServiceProvider::register()`. Verified against
  `vendor/symfony/process/Process.php`: passing `$env` to the constructor
  (or `setEnv()`) **merges on top of** the inherited environment at
  `start()` time (`$env += $this->env; $env += $this->getDefaultEnv();`),
  it does not replace it — this is what makes `GitService`'s conditional
  `GIT_SSH_COMMAND` injection safe; omitting the key from `$env` entirely
  (not passing it as `''`) is what makes it correctly absent downstream.
- `Tests\Support\FakeProcessRunner` (in `tests/Support/`, not `app/`, since
  it is test-only) — queue canned outcomes in call order, and every
  invocation is recorded to `->calls` (command, cwd, env, timeout,
  idleTimeout) for exact-argv assertions. Three queueable shapes now
  (extended for Phase 3 — see "Two contract changes" below):
  `queueSuccess()`/`queueFailure()`/`queueTimeout()`/`queue(ProcessResult)`
  for canned static results; `queueThrowable()` for the runner itself
  misbehaving; `queueCallable()` for dynamic behaviour a canned result
  can't express — emitting several chunks through `$onOutput` over one
  call, which is exactly Phase 3's "log chunks append on every chunk"
  need.

`GitService` and `ContainerStatus` both take a `ProcessRunner` via
constructor injection (defaulting to `app(ProcessRunner::class)` when the
container resolves them), exactly mirroring the reference `GitService`'s own
`constructor(sshKeyPath = null)` pattern. Neither shells out via a bare
`Process::fromShellCommandline()` or string command — always an argv array,
so there is no shell-interpolation surface.

### Two contract changes made for Phase 3, before Phase 3 existed to ask for them

QC flagged these as cheaper to fix now than to unpick later out of the
deploy job:

- **A stall is now a result, not an exception.**
  `reference/src/jobs/deployApp.ts:31-43` treats a stalled (no-output,
  idle-timed-out) process as a *normal* outcome: kill it, emit a message
  through the output callback, resolve with exit code 1, let the 3-attempt
  retry loop decide what to do. `SymfonyProcessRunner::run()` originally let
  `Symfony\Component\Process\Exception\ProcessTimedOutException` escape
  uncaught — which meant no `ProcessResult` ever existed for a stalled run,
  the exit code was lost, and `ProcessResult`'s own docblock claim ("no
  Symfony\Process type leaks past this boundary") was false the moment a
  process actually stalled. Fixed: `ProcessResult` gained a
  `public readonly bool $timedOut = false` field, and
  `SymfonyProcessRunner::run()` now catches `ProcessTimedOutException`
  internally and returns `new ProcessResult(1, $process->getOutput(),
  $process->getErrorOutput(), timedOut: true)` — exit code 1 to match the
  reference's contract exactly (not Symfony's own exit code, which a forced
  kill often doesn't have), plus whatever output was captured before the
  kill. `successful()` now also checks `! $this->timedOut`. Phase 3's job
  should branch on `$result->timedOut` to build its own stall message (the
  exact `\nERROR: process stalled — no output for Ns, killed.\n` text is
  deploy-job wording, not something the generic seam should hardcode) and
  feed it through the retry loop.
- **`FakeProcessRunner` gained a queued-callable and a queued-throwable
  form** (see above) specifically because the old "one `ProcessResult`,
  `$onOutput` fires once for `output` and once for `errorOutput`" model
  can't express either a multi-chunk stream or the stall path. `queueTimeout()`
  is the convenience for the common case — a canned `ProcessResult(1, ...,
  timedOut: true)`, mirroring what `SymfonyProcessRunner` itself now
  returns.

### SymfonyProcessRunner — real integration tests (QC B9)

`tests/Unit/Services/Process/SymfonyProcessRunnerTest.php` shells out to
real `bash`/`sleep`/`echo` rather than faking anything — the point is to
exercise the actual `Symfony\Process` integration, since it's the one
`ProcessRunner` implementation nothing else in the suite touches. Four
probes, each pinning something a fake can't:

1. **Output streams incrementally.** A command that prints three chunks a
   beat apart is asserted to invoke `$onOutput` more than once, with the
   first and last invocation timestamps spread over real wall-clock time —
   rules out an implementation that buffers everything until exit and
   calls the callback once.
2. **An idle timeout kills a genuinely silent process**, well before its
   own runtime would otherwise finish, and — per the stall contract above —
   returns a `ProcessResult` with `timedOut = true` and exit code 1 rather
   than throwing. Re-QC (R4) found the first version of this test used
   `['sleep', '5']`, which produces no output at all, so it could not tell
   whether output captured BEFORE the stall survives the kill — the actual
   diagnostic value for a hung 40-minute build. Fixed to print to both
   stdout and stderr before going silent, asserting both are still on the
   returned `ProcessResult`; verified by mutation (see "QC pass" above, R4).
3. **An idle timeout does NOT fire while output keeps arriving** — five
   ticks 0.4s apart against a 1.0s idle timeout must survive the full
   ~2s run; only a *total* timeout would have killed it, so this is what
   actually distinguishes `setIdleTimeout()` from `setTimeout()` in the
   real implementation, not just in the interface's parameter names.
4. **`$timeout = null` / `$idleTimeout = null` are not silently defaulted
   back to Symfony's own 60-second constructor default.** This is the
   single most dangerous line in the class — `buildProcess()`'s
   `$process->setTimeout($timeout)` with `$timeout = null` looks like a
   no-op and is exactly the kind of line a later "cleanup" removes. Made
   fast and deterministic via `ReflectionMethod` on the private
   `buildProcess()`, asserting `getTimeout()`/`getIdleTimeout()` are `null`
   when passed `null` and equal the passed value otherwise — this is why
   `buildProcess()` was split out of `run()` in the first place, purely for
   this test's sake. Verified by mutation: deleting the
   `setTimeout($timeout)` call reinstates Symfony's `60.0` default and this
   test catches it immediately (`Failed asserting that 60.0 is null`).
   **Scope, precisely (re-QC pushed back on overclaiming this):** this
   probe guards `buildProcess()`'s own construction logic, not the full
   `run()` call path — an inline `new SymfonyProcess(...)` inside `run()`
   that bypassed `buildProcess()` entirely (skipping `setTimeout()`/
   `setIdleTimeout()`, keeping Symfony's 60s default) would NOT be caught
   by this probe. Probes 2–3 above exercise `run()` end-to-end with real,
   short idle timeouts, so between the two, wiring and configuration are
   each covered by something — just not proven by the same test.

**The seam fits.** These four probes prove `ProcessRunner` can already
express everything Phase 3 needs — incremental streaming, idle-vs-total
timeout distinction, and stall-as-result. Phase 3 should not need to bypass
or extend this interface; if it finds itself wanting to, that's a signal to
re-read this section first.

### GitService — commands are verbatim, tests avoid the network

Every git invocation is copied argument-for-argument from
`reference/src/services/gitService.ts`, including `-c safe.directory=*` on
clone and the existing-branch-vs-new-branch checkout split inside `pull()`.
`GIT_SSH_COMMAND` is set on the `$env` array passed to `ProcessRunner::run()`
only when `file_exists($sshKeyPath)` — never unconditionally.

**Deviation from the reference test file, deliberate:**
`reference/tests/Unit/gitService.test.ts`'s 3 cases clone a real public
GitHub repo over the network. Per this port's testability requirement (no
real git remote, no real network), `tests/Unit/Services/GitServiceTest.php`
ports the same 3 behaviours against a `FakeProcessRunner` instead, and adds
8 more cases pinning the literal argv AND cwd of every sub-command
`pull()` issues, not just clone's — see "QC pass" above (B4/B5/B6) for the
three specific things that were unpinned in the first submission and how
they were closed.

### PortBindings — the colon-vs-bare distinction, verified by mutation

`app/Services/PortBindings.php::interpolate()` carries `$colon` and `$isSet`
as separate values into one `$usable` computation:
`$usable = $colon === ':' ? ($isSet && $val !== '') : $isSet;`. Collapsing
this to `$usable = $isSet` (i.e. treating the colon and bare operator
families identically) was tried as a live mutation — it fails
`test_colon_plus_alt_variant_treats_defined_empty_as_unset` and
`test_colon_minus_default_variant_treats_defined_empty_as_unset`. The
reference's own `portBindings.test.ts` never exercises this distinction (it
only tests `:-`). This port now has 32 PortBindings cases against the
reference's 15 — see "QC pass" above (B7/B8) for the operator-family and
quadrant gaps a first pass still left uncovered, plus the `.env` parsing
edge cases (comment lines, first-equals-only splitting) pinned via
reflection on the private `readDotenv()`, since neither is observable
through `PortBindings::read()`'s public surface alone.

### ContainerStatus — the `array_is_list([])` trap

`json_decode($line, true)` (associative mode) makes an empty JSON object
(`{}`) and an empty JSON array (`[]`) produce the identical PHP value `[]` —
and `array_is_list([])` is `true`. A first version of `ContainerStatus`
decoded with `assoc: true` and used `array_is_list()` to distinguish "this
line is a JSON array of containers" from "this line is one container
object," which silently dropped any container whose `docker compose ps`
JSON happened to be `{}` (caught by
`ContainerStatusTest::test_defaults_missing_fields`, which failed against a
real, not hypothetical, bug on the first test run — not a seeded mutation).
Fixed by decoding with `assoc: false` (`json_decode($line, false, ...)`) so
a JSON array stays a PHP array and a JSON object becomes `stdClass` — the two
are then unambiguous regardless of emptiness — and casting each item to
`(array)` afterwards. Worth remembering for Phase 3/5 if anything else
parses Docker's NDJSON output: prefer `assoc: false` plus a type check over
`assoc: true` plus `array_is_list()` whenever "was this JSON `{}` or `[]`"
matters.

Separately, the `! $result->successful()` non-zero-exit guard was itself
unpinned until QC: the original test queued a failure with *empty* stdout,
so removing the guard still fell through to the blank-output check right
after it and still returned `[]` — same result either way. Fixed by queuing
a failure with valid, non-empty JSON on stdout (a `docker compose ps` that
printed something before erroring), which only the exit-code guard now
stands between and a parsed result.

### DeploySteps — resolution priority and the malformed-JSON branch

`DeploySteps::resolve()` checks `file_exists($app->path.'/bridge.yml')`
first; only when absent does it fall through to `$app->deploy_steps`.
Verified by mutation: forcing that check to `false` (bypassing the repo
file entirely) drops `DeployStepsTest` from 24/24 to 18/24 — the 6 failures
are exactly the bridge.yml-specific cases (precedence, multi-step parsing,
and all 4 bridge.yml validation-error cases), confirming the priority order
and the YAML-vs-JSON branches are both actually exercised, not just
present.

As mandated by Phase 1's decision (`App::$deploy_steps` has no `array`
cast — see the Phase 1 section above), this service does its own
`json_decode($app->deploy_steps, true, 512, JSON_THROW_ON_ERROR)` wrapped in
a try/catch on `JsonException`, reproducing the reference's
`post-deploy: deploy_steps JSON parse error` message exactly
(`deploySteps.test.ts:120`'s case, `test_throws_on_malformed_json_in_deploy_steps`
here).

### HealthPoller — design decision and justification

**Behaviour change from the reference (explicitly requested):** the
reference writes `apps.health_check_interval` but never reads it — every app
is polled on the same hard-coded 60s cadence
(`reference/src/services/healthPoller.ts` has no interval logic at all).
`HealthPoller::isDue(App $app): bool` now gates on it, defaulting to 60s
when unset, and `pollDue()` skips any app not yet due. The two original
paired tests (300s interval against a just-created check; 1s interval
backdated 10 minutes) both happened to agree with the 60s default
regardless of whether the column was actually read — see "QC pass" above
(B2) for the case that was added specifically to make the per-app value
diverge from the default and pin that it's genuinely read, not just gated
on *something*.

**Invocation mechanism — the actual decision, not just the interval logic:**
Phase 2 excludes jobs/HTTP/Filament by scope, so `HealthPoller` is a
single-pass, side-effect-explicit service (`pollDue()`/`check()`) with no
loop and no scheduling of its own — something else has to call `pollDue()`
periodically, and *that* wiring is what Phase 7's supervisord constraint
(`web server + one queue:work`, nothing else) actually bears on.

Two "obvious" options were both rejected as costing a third process:

- **Laravel's scheduler** (`Schedule::command(...)->everyMinute()` in
  `routes/console.php`) needs *something* ticking `artisan schedule:run`
  once a minute — either host cron (not present/managed in this container)
  or `artisan schedule:work` as its own long-running process.
- **A bespoke long-running loop** (`while (true) { sleep($n); poll(); }` in
  a console command) is a process supervisord has to manage on its own,
  identical cost to the scheduler option.

The design this points to for whichever phase wires it up: **a
self-rescheduling queued job**, processed by the `queue:work` worker that
Phase 7's supervisord config already runs for deploys — `handle()` calls
`app(HealthPoller::class)->pollDue()` then
`self::dispatch()->delay(now()->addSeconds($tickInterval))` to requeue
itself. Zero extra processes, reuses infrastructure Phase 3 needs anyway
(`QUEUE_CONNECTION=database`, already configured in Phase 1). Not built
here because Phase 2 excludes job classes by scope; `pollDue()` is written
so that job's `handle()` is a one-line call.

Uses the `Http` facade (not raw cURL/Guzzle) specifically so `Http::fake()`
drives the tests — no real network, matching the cross-cutting testability
requirement.

**Fault tolerance is not optional under this design (QC B1).** An uncaught
exception anywhere inside one app's check is not a degraded pass under the
self-rescheduling-job shape above, it is a *permanent* outage — the job's
`handle()` never reaches its own `self::dispatch()->delay(...)` requeue
call, and there is no cron/scheduler backstop to notice health polling
silently stopped. This is why `pollDue()` wraps each app's `check()` in its
own try/catch (on top of, not instead of, filtering out `''` `health_url`
values at the query level — see "QC pass" above, B1) and why whichever
phase builds the actual job must put the `self::dispatch()->delay(...)`
call in a `finally`, not just at the end of a happy path — see "Forward
risks for Phase 3" below.

### SlackNotifier — no bare `app_name`

Confirmed directly against the Phase 1 note this was flagged under:
`Deployment::app()` is a `belongsTo` relation, not a flattened `app_name`
column, so `SlackNotifier::notify()` reads `$deployment->app?->name ?? 'App'`
— never `$deployment->app_name`. `SlackNotifierTest::test_uses_the_related_apps_name_via_the_belongs_to_relation`
specifically re-fetches a bare `Deployment` (`Deployment::find($id)`, relation
not eager-loaded) rather than reusing the just-created model, so the relation
genuinely lazy-loads rather than the test accidentally passing because the
in-memory instance still happens to carry the relation from creation.

### `symfony/yaml` added as a dependency

Neither `docker-compose.yml` parsing (`PortBindings`) nor `bridge.yml`
parsing (`DeploySteps`) has a YAML parser available in the Phase 0/1
dependency set — `vendor/symfony/yaml` did not exist before this phase.
Added via `composer require symfony/yaml` (resolved to `^8.1`, matching the
other Symfony components Laravel 13 already pulls in). No other new runtime
dependencies were added; `Symfony\Component\Process` was already present
transitively (`illuminate/process` requires it).

### Known, deliberately unaddressed — recorded by QC, not fixed

None of these need action now; each is either genuinely equivalent
behaviour, out of Phase 2's scope, or a real gap too small to justify the
risk of touching working code for. Flagging them here so a later phase
doesn't have to re-derive the reasoning.

- **`HealthPoller::pollDue()` is N+1.** One `HealthCheck::findLatest()`
  query per app to determine due-ness. Fine at today's scale; if the apps
  table grows large this could be a single query with a subquery/window
  function instead.
- **`BRIDGE_REPOS_PATH=` (explicitly set to empty) diverges from the
  reference.** `appValidators.ts:9` uses `process.env.REPOS_PATH || '/repos'`
  — the `||` treats an empty string the same as unset, falling back to
  `/repos`. This port's `env('BRIDGE_REPOS_PATH', '/repos')` only applies
  its default when the variable is *absent*, so an explicitly-empty value
  yields `''`, not `/repos`. Unlikely to matter in practice (nobody sets a
  var to intentionally empty just to get the default), but it's a real,
  named divergence, not an oversight.
- **A single invalid UTF-8 byte anywhere in `docker-compose.yml` drops ALL
  port bindings, not just the affected service's.** `PortBindings::read()`'s
  catch-all (`catch (Throwable) { return []; }`) is what the "every failure
  returns `[]` silently" spec explicitly asks for, so this is within spec —
  but it's a behavioural regression from the reference, which would still
  list every other service's bindings. Not fixed because loosening the
  catch-all to be more granular is exactly the kind of scope-creep that
  risks reintroducing an exception leaking to the UI, which is the one
  thing this service must never do.
- **Style split: some Phase 2 services are injectable, some are
  static-only.** `GitService`, `ContainerStatus`, and `HealthPoller` take
  constructor dependencies (a `ProcessRunner`, or none, resolved through the
  container); `PortBindings`, `DeploySteps`, and `SlackNotifier` are
  static-only classes with no instance state. This means Phase 4/5 can bind
  a fake for the first three via the container but cannot mock the latter
  three the same way — if that ever becomes a real testing need (rather
  than the current direct-static-call-with-real-filesystem-fixtures
  approach, which works fine because these three don't shell out or hit the
  network), they'd need a matching interface-and-injection refactor.
- **`DeploySteps::validateSteps()`'s `trim()` calls and the serializer's
  `": "` separator are unpinned mutants — both effectively equivalent.**
  Removing `trim()` only matters for hand-authored `bridge.yml`/UI text
  with stray whitespace around `service`/`run` values, which nothing in the
  24-case suite happens to include with meaningfully different before/after
  trim content; the serializer's exact separator round-trips correctly
  through `parseUiSteps()` regardless of minor spacing changes since
  `parseUiSteps()` itself trims. Not worth adding brittle whitespace-only
  assertions for.

### Forward risks for Phase 3 — read this before writing the deploy job

Phase 3 starts in a fresh session with no memory of this QC round. These
four are not addressed by any code in Phase 2 and must inform Phase 3's
design:

- **`queue:work`'s default timeout will kill `DeployApp` at 60 seconds.**
  Laravel's queue worker has a 60s per-job timeout by default. A 40-minute
  build needs BOTH `public $timeout = 0;` on the job class AND
  `--timeout=0` on Phase 7's supervisord `queue:work` invocation — the job
  property alone is not sufficient if the worker process itself was started
  with a shorter timeout flag, and vice versa. Nothing in the phase plan or
  these notes mentioned this before now, and it is the most likely way
  Phase 3 "works in every test, dies the first time it runs in the real
  container" for a build that legitimately takes longer than a minute.
- **Unbounded log buffering.** `Symfony\Process` retains the full stdout/
  stderr in memory (`getOutput()`/`getErrorOutput()`) for the life of the
  process, even when output is also being streamed via `$onOutput` and
  persisted incrementally (`Deployment::appendLog()`) — so a 40-minute
  build's entire log sits in the worker's memory regardless. Calling
  `disableOutput()` is not a free escape: Symfony forbids it together with
  either an idle timeout or an output callback, both of which Phase 3
  needs. Practical guidance: Phase 3's deploy path should pass `$onOutput`
  and route persistence through it, and simply never read
  `ProcessResult->output` for the main build step (only for the short,
  Phase-2-style calls that don't stream). Phase 7 should size the
  `queue:work` process's `memory_limit` with a long, verbose build in mind.
- **The seam fits — Phase 3 should not need to bypass it.** Restated from
  the SymfonyProcessRunner section above because it's the single most
  reassuring thing to know before starting Phase 3: incremental streaming,
  the idle-vs-total timeout distinction, and stall-as-a-result are all
  proven to work against a real process, not just asserted against a fake.
- **`HealthPoller` on a single `queue:work` does not survive Phase 7 as
  designed, without one more decision.** The self-rescheduling-job shape
  (zero extra processes, reuses `QUEUE_CONNECTION=database`) is the right
  call, and rejecting the scheduler and a bespoke loop is correctly
  reasoned — but two problems remain unsolved by the shape alone:
  1. **Head-of-line blocking.** A single `queue:work` process runs one job
     at a time. A 40-minute deploy job blocks health polling for the full
     40 minutes; conversely, an app poll pass against N unreachable apps
     (each blocking up to the 10s request timeout) can delay a queued
     deploy by up to N×10s before it even starts.
  2. **The reschedule chain is not fault-tolerant by construction.** Any
     uncaught throw inside the health job's `handle()` — not just the B1
     bug this phase already hardened `pollDue()` against, but anything
     Laravel's own retry mechanism eventually gives up on after
     `$tries = 3` — skips the `self::dispatch()->delay(...)` call, and
     health polling silently stops forever with no alarm anywhere. The
     requeue call MUST be in the job's `finally` block (or an equivalent
     `catch`-and-still-requeue), not just at the end of a happy path.

  Recommendation to record for whichever phase builds the job: give health
  polling its own queue name (e.g. `php artisan queue:work
  --queue=deploys,health` on the single worker so deploys are always
  prioritized, or a dedicated queue connection if the head-of-line problem
  proves worse in practice than it looks on paper) so Phase 3's long builds
  and Phase 7's supervisord config can be reasoned about independently. And
  whichever phase writes `handle()` should re-run B2's mutation
  (`$interval = self::DEFAULT_INTERVAL_SECONDS` hardcoded) against the new
  job to confirm the interval-honoring behaviour survived the move from
  `HealthPoller::pollDue()` being called directly to being called from
  inside a job.

## Parity acceptance

`reference/tests/` and `reference/src/services/*.test.ts` hold **115 cases**.
They are the behavioural contract for this port — every one should map to a
ported test. The two SSE tests are replaced by polling tests; HTML feature tests
become authenticated Filament tests.

(The reference README claims 35 tests. That is stale.)
