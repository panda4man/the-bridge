# Laravel + Filament Port — Working Notes

Living notes for the Express → Laravel rebuild. Read this before starting a
phase. Add to it whenever you learn something a later phase would otherwise
have to re-derive.

## Resuming work

| What | Where |
|---|---|
| Phase plan | `~/.claude/plans/sorted-marinating-goose.md` |
| Phase tracking | The task list — each task carries its own QC notes |
| Specification | the Express original — **deleted from this branch in Phase 8** |
| Branch | `laravel-port`, in a worktree at `../the-bridge-laravel` |

`reference/` held the original Express app and was the source of truth for
behaviour through Phases 1–7. Phase 8 deleted it, as planned. Roughly 80 files
still cite paths under it (`reference/src/routes/apps.ts:195`, and so on) — the
citations were deliberately left alone, because they are the provenance of
nearly every non-obvious decision in this port. To read one:

```bash
git show main:src/routes/apps.ts          # the Express app still lives on `main`
git show ea4d6f2:reference/src/routes/apps.ts   # or the last commit that had reference/
```

Phase 8's parity map (below, "Parity acceptance") records how all 115 of its
test cases landed here, so the mapping survives the deletion.

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
- **`apps.path`'s unique index reaches Phase 5, not just Phase 4.**
  *(Superseded — Phase 5 found the API/webhook app-creation endpoints this
  bullet anticipates do not exist. See "Corrections to earlier notes" in the
  Phase 5 section.)* A
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

## Phase 3 — the deploy pipeline

`app/Jobs/DeployApp.php` ports `reference/src/jobs/deployApp.ts` — the git
phase, the compose phase (3 sub-commands × up to 3 attempts each), the
post-deploy phase (pre-flight + exec), success/failure bookkeeping, Slack
notification, and auto-rollback. `App\Services\GitService` gained three
methods (`checkout()`, `revParseHead()`, `lastCommitSubject()`) so every git
argv the job needs still lives in one place, per Phase 2's own guidance.

`php artisan test` — **211 tests, 535 assertions, all green.** Measured
breakdown, not arithmetic (an earlier version of this paragraph claimed
"207 = 162 + 6 + 23 + 6", which sums to 197; QC round 2 caught it):

| | tests | assertions |
|---|---|---|
| Phase 2 baseline, unchanged by this phase | 151 | — |
| `GitServiceTest` (11 at Phase 2 + 6 new here) | 17 | 45 |
| `DeployAppTest` (new this phase) | 43 | 193 |
| **Total** | **211** | **535** |

`DeployAppTest`'s 43 cases are 8 ported reference cases plus the argv/env/
timeout pinning tests, plus 18 added by QC fix round 1 (B1–B18), 6 by round 2
(B6-full, B8, B9, B10, B12, B13 — see "Verified by mutation" below), and 4 by
round 3 (see "QC round 3" at the end of this section).

### The process seam needed no changes

Phase 2 predicted this and it held: `ProcessRunner::run()`'s existing
`idleTimeout`/`timeout: null`/`$onOutput` signature and the stall-as-a-result
contract (`ProcessResult->timedOut`) covered everything the job needs —
incremental log streaming into `Deployment::appendLog()`, the idle-vs-total
timeout split (60s for compose `pull` and for exec steps, 300s for `down`/
`up`), and treating a stall as a retryable exit-code-1 rather than a thrown
exception. Nothing in `App\Services\Process\` was touched this phase.

### No injected `composeRunner`/`execStep` closures — faked one layer down

The reference's `DeployAppOptions` accepts `composeRunner`/`execStep`
overrides so its tests can swap in a mock without a real `spawn()`. This port
has no equivalent constructor option: `DeployApp` builds the `docker compose
...`/`docker compose exec ...` argv itself and calls `ProcessRunner::run()`
directly, the same seam `GitService`/`ContainerStatus` already use. Binding
`Tests\Support\FakeProcessRunner` as the container's `ProcessRunner` makes
every shell-out the job performs — git, compose, exec — observable by argv,
env, cwd, timeout, and idleTimeout, which is strictly more than the
reference's own tests ever pinned (they never asserted the literal `docker
compose` command line at all).

### Tests call `handle()` directly, not `::dispatch()` — and why that matters

`phpunit.xml` sets `QUEUE_CONNECTION=sync` for the test environment. Under a
sync queue, `Job::dispatch()` runs the job **synchronously, in-process**, not
"later." That means the auto-rollback's `self::dispatch($rollback->id)`
inside the catch block would, if the *outer* test invocation also went
through `DeployApp::dispatch()`, actually execute a second full deploy
recursively inside the same test — consuming more of the same
`FakeProcessRunner`'s queued responses in an order the test didn't plan for,
and, since the guard against chaining checks `rollback_sha` (not a depth
counter), running to completion on responses the test never queued for it.
(This used to read "silently resolving via the fake's empty-queue default" —
`FakeProcessRunner` no longer has one; QC round 3 made an exhausted queue
throw. The recursion hazard itself is unchanged, it now just fails loudly.)

Every test in `tests/Unit/DeployAppTest.php` instead calls
`app()->call([new DeployApp($id), 'handle'])` — direct method invocation via
the container, which resolves `GitService`/`ContainerStatus`/`ProcessRunner`
exactly as a real queue worker would, but does **not** go through the Bus
dispatcher for the invocation under test. The *only* place a nested dispatch
can still happen is the auto-rollback call itself, and the tests that reach
it wrap it in `Bus::fake()` and assert on it explicitly
(`Bus::assertDispatched(DeployApp::class, fn ($job) => $job->deploymentId ===
$rollback->id)` / `Bus::assertNotDispatched(...)`), rather than letting a
second deploy actually run. Phase 4/5, which will call `DeployApp::dispatch()`
for real, don't need to know any of this — it's purely a test-construction
concern — but a future phase adding more `DeployApp` tests should keep using
`app()->call(...)` rather than `::dispatch()` for the same reason.

### Constructing an "App not found" test required working around SQLite's FK-pragma-mid-transaction rule

`deployments.app_id` has `->constrained('apps')->cascadeOnDelete()` (Phase
1), so under normal operation deleting an `App` also deletes its
`Deployment`s — meaning `Deployment::find()` would already fail before
`App::find()` is ever reached, and "App {id} not found" looks structurally
unreachable. Reproducing the scenario for a test (an orphaned `Deployment`
row whose `app_id` points nowhere) requires disabling FK enforcement for one
`DELETE`. That in turn ran into a second, sharper problem: **SQLite silently
ignores `PRAGMA foreign_keys = OFF` while a transaction is open**, and
`RefreshDatabase` wraps every test in one — verified empirically (querying
the pragma immediately after "disabling" it inside a normal test still
reports `1`, and the delete still cascades).

`DeployAppTest::test_throws_when_app_not_found` works around this by calling
`DB::commit()` to close `RefreshDatabase`'s wrapping transaction, toggling
the pragma, deleting, re-enabling FK enforcement, and reopening a transaction
(so `RefreshDatabase`'s own `tearDown()` still has one to roll back). The
side effect: this test's writes are genuinely committed to the shared
`:memory:` SQLite connection (which persists across test classes within one
`php artisan test` run), so the test explicitly deletes its own orphaned row
in a `finally` block, committing *that* too — otherwise it leaks into every
test that runs afterward in the same process. This was not theoretical: the
first version of this test left the orphan behind, and
`ResetStuckDeploymentsTest`'s exact-count assertions failed as a downstream
symptom. Confirmed clean afterward by running the full suite with
`--order-by=random` three times.

Net effect for later phases: "App {id} not found" is real defensive code —
worth keeping, since nothing prevents a future schema change from decoupling
the two tables — but under the current cascade constraint it cannot happen
via ordinary use. Phase 4/5 do not need to build any UI/API path that
triggers it.

### Verified by mutation

Every mutation below was applied by hand, the targeted test(s) re-run and
confirmed failing, then the code restored and the full suite re-confirmed
green:

- Removing `Deployment::findLastSuccessful()`'s `->where('app_id', $appId)`
  filter (the multi-app rule from Phase 1, applied here too — see
  `test_auto_rollback_only_considers_the_failing_apps_own_deployment_history`,
  which deliberately gives a *second* app the higher-id successful
  deployment so an unscoped query would prefer the wrong SHA): caught, wrong
  SHA asserted.
- Folding the post-deploy pre-flight loop into the exec loop (checking and
  executing each step in the same pass instead of a check-everything, then
  exec-everything, sequence): caught by
  `test_preflight_checks_all_steps_before_any_exec_runs`, which uses two
  steps (`web` running, `worker` not) specifically so an interleaved
  implementation would visibly exec `web` before ever reaching the failing
  check on `worker`.
- Changing the retry guard from `$attempt < 3` to `<= 3` (logging a spurious
  third "Retrying in 5s..." after the final, exhausted attempt): caught by
  `test_compose_exhausts_all_three_attempts_and_logs_exactly_two_retry_messages`,
  which asserts the retry-message count is exactly 2, not "at least 2".
- Adding `throw $e;` at the end of the catch block (the single most
  important non-negotiable in the phase brief — "a failed deploy is a
  successful job"): caught immediately — 19 of the file's cases error out
  with the uncaught `RuntimeException` instead of asserting a `failed`
  status. (Re-measured in QC round 2; the figure originally recorded here,
  "10 of 23", predated the round-1 fix tests.)
- Deleting the `if ($dep->rollback_sha) { return; }` loop guard in
  `autoRollback()`: the *original* version of
  `test_rollback_deploy_does_not_enqueue_another_rollback_loop_guard` did
  **not** catch this — that app had no prior successful deployment, so
  `findLastSuccessful()` returned `null` regardless of whether the guard ran,
  and the mutation left all 23 tests green. Fixed by giving the app a prior
  successful deployment before the rollback-flavoured deploy runs, so the
  guard's absence is actually observable (a second, wrongly-chained rollback
  gets created); re-ran the mutation afterward and it now fails as expected.
  Recorded here because it's the same "coverage looked plausible but wasn't"
  trap Phase 1/2 QC kept finding, caught this time before QC rather than
  after.
- Removing the `try { SlackNotifier::notify($dep); } catch (Throwable) {}`
  wrapper in `notify()`: caught by
  `test_slack_notification_failure_does_not_block_deploy_or_auto_rollback`
  (`Setting::setValue('slack_webhook_url', ...)` plus
  `Http::fake(fn () => throw new ConnectionException(...))`), which errors
  out with the uncaught connection exception instead of completing.
- Removing the `if ($result->timedOut) { ... return 1; }` branch from both
  `runCompose()` and `runExecStep()` (falling through to `return
  $result->exitCode`, which happens to also be `1` for a canned timeout
  result — so this mutation is invisible to any test that only checks the
  final status): caught by the two `test_compose_stall_on_*` tests and
  `test_exec_step_stall_logs_...`, which assert the exact `\nERROR: process
  stalled — no output for {N}s, killed.\n` text is present, not just that
  the deploy eventually failed or retried.
- Hardcoding `$idleTimeout = 300.0` (removing the `str_starts_with($subCmd,
  'pull')` branch): caught by
  `test_compose_phase_issues_the_exact_verbatim_command_sequence_with_ssh_key_present`,
  which asserts `idleTimeout === 60.0` specifically for the `pull`
  sub-command.
- Adding `DOCKER_PROGRESS` to the exec-step env (the reference deliberately
  omits it there — see "Command shapes" in the phase brief): caught by both
  `test_exec_step_issues_the_exact_verbatim_command_with_ssh_key_present_and_no_docker_progress`
  and `test_exec_step_omits_git_ssh_command_when_key_file_is_missing`, which
  assert the exec env array exactly, not just that `GIT_SSH_COMMAND` is
  present when expected.

**Second QC round — B6-full, B8, B9, B10, B12, B13:**

- **B6-full — the App lookup, and the two app-typed call sites it feeds,
  were never proven app-scoped.** Every other test in the file creates only
  one `App`, so `App::find($dep->app_id)` and something unscoped like
  `App::query()->orderByDesc('id')->first()` are indistinguishable — both
  return the only row in the table. Fixed with
  `test_app_lookup_and_every_downstream_call_are_scoped_to_the_deployments_own_app`,
  which creates three apps with distinct paths and distinct `deploy_steps`,
  deliberately makes the deployed app (`appB`) the MIDDLE id (neither
  highest nor lowest), and asserts every recorded process call — git,
  compose, `docker compose ps`, exec — ran with `appB`'s path as cwd, that
  the exec step used `appB`'s own service/command (not `appA`'s or
  `appC`'s), and that `appA`/`appC` are untouched (status unchanged, zero
  deployment rows). One test, four mutations, each verified individually
  and restored: `App::find($dep->app_id)` → `App::query()->orderByDesc('id')->first()`
  (caught — resolves the whole deploy against the wrong app, cwd mismatch
  on every call); the same swapped to `orderBy('id')->first()` (caught —
  `appB` being the middle id means BOTH ascending and descending
  single-extreme queries land on a different, wrong app); the identical
  swap at the `DeploySteps::resolve(...)` call site, both directions
  (caught — the wrong app's `deploy_steps` get resolved, so pre-flight
  checks a service name that was never queued as running and the deploy
  fails instead of succeeding); and `ContainerStatus::forWorkDir('/nowhere')`
  (caught directly — the `docker compose ps` call's cwd assertion fails
  with `'/nowhere'` instead of `appB`'s path).
- **B8 — the post-deploy pre-flight loosened to `!== null` would still
  pass.** `test_preflight_fails_when_the_matching_service_is_present_but_not_running`
  queues a container whose `State` is `"exited"` (present, but not
  running) and asserts pre-flight still fails, the deployment is marked
  `failed`, and zero exec calls were made. Verified by mutation: relaxing
  `($container['state'] ?? null) === 'running'` to `!== null` flips this
  test from failed to successful — caught.
- **B9 — the auto-rollback guard's `$previous->commit_sha` half had no
  test that could actually observe it.** A genuinely NULL `commit_sha` is
  already excluded by `findLastSuccessful()`'s own `whereNotNull('commit_sha')`
  filter (confirmed by reading the method, not run as a separate probe), so
  `$previous` is `null` either way — a test built around a literal NULL
  commit_sha cannot discriminate `if ($previous && $previous->commit_sha)`
  from `if ($previous)`; both branches see a null `$previous` and behave
  identically, so that framing was abandoned before being written, not
  disproven empirically. An empty-STRING `commit_sha` passes the NOT NULL filter
  (`$previous` comes back non-null) while still being falsy in PHP, which
  is what actually exercises the guard's second condition.
  `test_auto_rollback_guard_requires_a_truthy_commit_sha_not_just_a_non_null_previous_deployment`
  uses this and asserts the exact `No previous successful deployment —
  skipping auto-rollback` log line (verbatim em dash) plus zero rollback
  rows created. Verified by mutation: `if ($previous && $previous->commit_sha)`
  → `if ($previous)` makes this test fail with a rollback wrongly created
  (log shows `Auto-rolling back to ` with an empty SHA) — caught.
- **B10 — `findLastSuccessful()`'s `beforeId` bound had no dedicated test.**
  The existing multi-app test
  (`test_auto_rollback_only_considers_the_failing_apps_own_deployment_history`)
  only pins the `app_id` filter, not the `id < beforeId` bound — a single
  app can't separate "scoped to earlier deployments" from "scoped to
  everything." `test_auto_rollback_never_selects_a_successful_deployment_created_after_the_failing_one`
  creates the failing deployment FIRST (lower id), then a successful
  deployment with a HIGHER id afterward, and asserts no rollback is
  enqueued. Verified by mutation: `findLastSuccessful($dep->app_id, $dep->id)`
  → `findLastSuccessful($dep->app_id, $dep->id + 100000)` makes the later,
  higher-id deployment wrongly qualify — a rollback row gets created where
  none should exist — caught.
- **B12 — `$tries`/`$timeout` were never asserted, anywhere.**
  `test_job_tries_and_timeout_properties_are_pinned` instantiates a bare
  `DeployApp` and asserts `tries === 3` and `timeout === 0` directly.
  Verified by mutation: `$tries = 3` → `1` and, separately, `$timeout = 0`
  → `60` each fail this one test with the obvious "expected 3, got 1" /
  "expected 0, got 60" message — caught both times.
- **B13 — a try/catch around `DeploySteps::resolve()` that swallowed its
  throw would silently report a broken deploy as successful.**
  `DeploySteps::resolve()` is documented (Phase 1's "deploy_steps
  serialisation decision" above) to throw `post-deploy: deploy_steps JSON
  parse error` on malformed JSON — nothing in the existing suite actually
  drove a malformed `deploy_steps` column through the job.
  `test_malformed_deploy_steps_json_fails_the_deploy_instead_of_silently_running_zero_steps`
  sets `deploy_steps` to `'{not json'` and asserts the deployment ends
  `failed` with that exact message in the log. Verified by mutation:
  wrapping the `DeploySteps::resolve($app)` call in
  `try { ... } catch (Throwable) { $resolved = ['steps' => [], 'source' => 'none']; }`
  makes this test fail — the deploy reports `success` with zero steps run
  instead of failing loudly — caught.

**Traps for later phases (4–7), generalized from this round:**

- **A per-item flag needs a failure on a *later* item to discriminate.**
  The pre-flight-then-exec pass structure only proves "runs before" if the
  earlier item passes and a *later* one fails (see
  `test_preflight_checks_all_steps_before_any_exec_runs`, first QC round).
  The same shape applies anywhere a loop short-circuits, retries, or gates
  on a per-item condition: a test where every item behaves identically
  can't tell "checked all of them" from "checked one and assumed the
  rest," and can't tell "the guard fired" from "there was nothing for the
  guard to catch."
- **stdout coverage does not imply stderr coverage.** Phase 2/3 both
  re-learned this (B2 in the first QC round, for `runCompose`/`runExecStep`'s
  `onOutput` callback) — a fake/mock that feeds a single output stream by
  default will silently leave the other stream's wiring unpinned. Any test
  double with separate stdout/stderr channels needs at least one assertion
  that exercises each channel independently, not just the aggregate log.
- **The two-app rule applies to the *entity lookup*, not only to scoped
  queries.** Phase 1's original lesson (see "Multi-app scoping filters
  need multi-app tests" above) was about `WHERE app_id = ?` clauses. B6-full
  is the same lesson one level up: `App::find($id)` has no explicit scope
  to drop, but it's just as vulnerable to being silently replaced by an
  unscoped "grab a row" query — and a single-app test suite can't catch
  that mutation any more than it could catch a missing `WHERE` clause. Any
  code path that resolves a specific entity by id — not just a query that
  filters a collection — needs a multi-row test proving the *right* row
  came back, not just *a* row.

### Things left out, deliberately

- **No `sshKeyPath` constructor override on `DeployApp`.** The reference's
  `DeployAppOptions.sshKeyPath` exists mainly so its tests can point at a
  fixture key; this port's tests instead mutate `config(['bridge.ssh_key_path'
  => ...])` directly (the same config Phase 2's `GitService` reads), so the
  override was never needed. `GitService`'s own constructor still accepts an
  explicit path for the same reason it did in Phase 2.
- **`runCompose()`/`runExecStep()` are private, not extracted into a
  service.** They're a thin, job-specific wrapper around
  `ProcessRunner::run()` (argv assembly, env assembly, the stall-message
  branch) with no reuse target elsewhere in the app — extracting them would
  be the "speculative abstraction" the engineering brief warns against.
- **No `ProcessRunner` changes.** Phase 2's QC pass already predicted and
  built everything this phase needed of the production seam (see "The seam
  fits" in the Phase 2 section above); this phase is the confirmation.
  `FakeProcessRunner` did change, in QC round 3 — see below.

### QC round 3 — the second adversarial pass

A second independent mutation pass (136 mutations, one at a time, full suite
per mutation, restore verified by hash) found **no defect in Phase 3
production code**. `app/Jobs/DeployApp.php` was not modified by this round.
String parity against `reference/src/jobs/deployApp.ts` is exact, including
all four U+2014 em dashes. What it did find was seven test-side gaps. All are
now closed, and each fix was verified by re-applying the exact mutation that
had survived, confirming the suite goes red, confirming the *new* test is
what fails, and restoring.

- **`FakeProcessRunner` is now strict about an exhausted queue.** It used to
  fall back to `new ProcessResult(0, '', '')`, so a test could queue fewer
  responses than the code makes calls and still pass, with the surplus calls
  silently "succeeding" with empty output. It now throws a `LogicException`
  naming the argv that overran. **Queue exactly one response per expected
  process invocation** in Phase 4+ tests.
  - This was not hypothetical:
    `test_deployment_and_app_are_marked_running_and_deploying_before_the_pipeline_starts`
    hand-rolled its git queue, answered `git rev-parse --abbrev-ref HEAD`
    with `''`, and so drove `GitService::pull()` down its branch-MISMATCH
    path (`branch --list`, `checkout -b --track`) — which ate the two
    commit-capture responses, left `commit_sha` empty, and overran the queue
    by two calls. It passed while exercising a different code path than its
    name claims. Making the fake strict fails **exactly** that one test
    across the whole suite; it is now realigned and asserts `commit_sha`.
- **`queueCommitCapture()` answers off the argv, not positionally.** Swapping
  the two assignments in `runGitPhase()` —

    ```php
    $dep->commit_sha     = $git->revParseHead($app->path);
    $dep->commit_message = $git->lastCommitSubject($app->path);
    ```

    — survived the whole suite, because swapping them also swaps which
    positional response is dequeued, so both columns still landed correct.
    Highest-consequence gap of the round: a commit *subject* in `commit_sha`
    is what `autoRollback()` writes into `rollback_sha` and the rollback
    deploy then hands to `git checkout`, so every later auto-rollback would
    run `git checkout "Fix the thing"`. The helper now answers `rev-parse
    HEAD` and `log -1 --format=%s` by matching the argv, and `fail()`s on
    anything else.
- **The intermediate `$dep->save()` after the git phase is now pinned.**
  Deleting it survived — the columns stay dirty and get flushed by the later
  `save()`. Not equivalent: `deployApp.ts:114` persists the SHA *before* the
  compose phase, which `$timeout = 0` exists to let run for 40 minutes, so
  the mutant leaves `commit_sha` NULL for that whole window (Phase 5/6 live
  polling would show no commit for the entire build) and loses it outright
  on a worker kill. Pinned by
  `test_commit_sha_and_message_are_persisted_before_the_compose_phase_starts`,
  which reads `$dep->fresh()` from inside a `queueCallable()` at the
  compose-`pull` slot.
- **A non-`RuntimeException` `Throwable` now goes through `handle()`.** Every
  throwable in the file was a `RuntimeException`, so narrowing
  `catch (Throwable $e)` to `catch (RuntimeException $e)` survived — and
  under that mutant an `Error` escapes `handle()`, leaves the deployment
  stuck in `running`, and `$tries = 3` re-runs the entire deploy three times.
  The new test queues an `\Error` at `GitService::pull()`'s `git config
  safe.directory *` call, which is deliberately not routed through that
  class's `mustRun()` and has no try/catch of its own — so it reaches
  `handle()`'s catch rather than being absorbed by `runCompose()`'s or
  `runExecStep()`'s own `catch (Throwable)`.
- **Compose exit codes other than 0 and 1 are now exercised.** Every failure
  in the suite was exit 1 (`queueFailure(1, ...)` or a stall, which also
  reports 1), leaving `if ($exit === 0)` indistinguishable from
  `if ($exit !== 1)` — under which a `docker compose up` exiting 125 (daemon
  unreachable) or 127 (binary missing) is treated as SUCCESS and the
  post-deploy steps run against containers that never started. One
  `queueFailure(125, ...)` case closes it.
  - *Correction to the round-2 findings doc:* it listed
    `return $result->exitCode;` → `return $result->exitCode === 0 ? 0 : 1;`
    as a second survivor under the same finding. Re-checked: that one is an
    **equivalent mutant**, and the new test does not kill it. Both call sites
    only ever compare `$exit` against `0`; the exact non-zero value is never
    logged, stored, or rethrown, and the reference does the same
    (`resolve(code ?? 1)` at `deployApp.ts:48`). Nothing to fix.
- **The exec-path spawn-failure assertion passed for the wrong reason.**
  `assertStringContainsString("\nERROR: spawn ENOENT\n", ...)` was satisfied
  by *adjacent* content — the leading `\n` from the preceding exec header,
  the trailing `\n` from the outer catch's own `\nERROR: post-deploy step
  failed…` — so stripping `runExecStep()`'s framing entirely left the
  substring intact. (`runCompose()` escaped this only because its next line
  starts `=== docker compose pull (attempt 2) ===`.) Now anchored to the
  exec header immediately preceding it.
- **`notify()`/`autoRollback()` ordering in the catch block is now pinned.**
  Swapping them survived, though the port's order matches the reference
  (`deployApp.ts:166` then `:169`). It *is* observable: `SlackNotifier`
  attaches the last 20 log lines on failure only, so a rollback appended
  first leaks its `Auto-rolling back to <sha>` line into the Slack payload of
  the deployment that just failed.
- **Test renamed.** `test_exec_step_stall_..._and_is_retried` →
  `..._and_is_not_retried`, with an exec-call-count assertion. Exec steps
  correctly have no retry (`deployApp.ts:147-153`); the old name contradicted
  both the code and its sibling.

Latent, not a live defect, worth knowing for Phase 4+: the in-memory SQLite
database **does** persist across test classes within one process
(`sqlite_sequence` shows ids leaking from the "App not found" test's
deliberately committed rows). No test currently depends on ids starting at 1.

### For Phase 4 (Filament actions), Phase 5 (API/webhook), Phase 7 (worker packaging)

- **Dispatch with `App\Jobs\DeployApp::dispatch($deployment->id)`** after
  creating a `pending` `Deployment` row. The job resolves the `Deployment`
  and `App` itself from the id; nothing else needs to be passed in.
- **`public $timeout = 0;` on the job is necessary but not sufficient.**
  Laravel's queue worker enforces its own default 60-second timeout on top
  of the job property. Phase 7's supervisord config MUST start the worker
  with `php artisan queue:work --timeout=0` (or an equally explicit
  override) — a `docker compose up -d --build` on a real app can legitimately
  run far longer than 60 seconds, and the job property alone will not save a
  worker started without it.
- **Auto-rollback dispatches a second `DeployApp` job onto the same default
  queue.** No custom queue name was introduced (per the brief). If Phase 2's
  recommended `health` queue (a separate name for the self-rescheduling
  `HealthPoller` job) is adopted in whichever phase builds it, remember
  `DeployApp` — including the rollback it can chain — stays on `default`;
  only give `health` its own name, so a single `queue:work --queue=deploys,health`
  (or equivalent) still prioritizes deploys correctly.
- **`Deployment::appendLog()` runs at 2 DB queries per output chunk** (Phase
  1 note, restated because this phase is the first real high-volume caller):
  a 40-minute build streaming output every second is ~4,800 queries against
  the same row. Nothing in this phase's scope changes that; if it ever
  becomes a real bottleneck, batching/coalescing chunks before calling
  `appendLog()` is the likely fix, not changing `appendLog()`'s own
  read-after-write safety.
- **The job never reads `ProcessResult->output` for compose/exec calls** —
  only the short `GitService::revParseHead()`/`lastCommitSubject()` calls do.
  A future change that "simplifies" by reading `$result->output` after a
  compose call instead of relying on `$onOutput` would silently reintroduce
  the unbounded in-memory log buffering Phase 2 flagged as a real risk for a
  long, verbose build — the callback is the only sanctioned path.

## Phase 4 — the Filament panel

`php artisan test` — **283 tests, 804 assertions, all green**, in fixed order
and under random-order seeds 1, 4242 and 31337. Measured breakdown of the 72
added this phase:

| | tests |
|---|---|
| `tests/Unit/Services/AppProvisionerTest` | 22 |
| `tests/Feature/Filament/CreateAppTest` | 11 |
| `tests/Feature/Filament/SettingsPageTest` | 11 |
| `tests/Feature/Filament/AppActionsTest` | 10 |
| `tests/Feature/Filament/EditAppTest` | 8 |
| `tests/Feature/Filament/DeploymentResourceTest` | 8 |
| `tests/Feature/Filament/PanelSmokeTest` | 2 |

**Filament is v5.7.4, on Laravel 13.23 / PHP 8.4.** The plan's snippets are
v3-shaped and do not apply. What actually changed: forms, infolists and the
custom page's schema are all `Filament\Schemas\Schema`; every action type
(page header, table row, bulk) lives under `Filament\Actions\`; resources are
generated into a per-model directory with `Schemas/`, `Tables/` and `Pages/`
subdirectories. `php artisan make:filament-resource` was used to scaffold and
the generated shape then rewritten — that is the reliable way to read the
installed API rather than guessing at it.

### URL parity: the apps list owns `/`, not a Dashboard

The reference serves the app list at `GET /`, and
`reference/tests/Feature/appCrud.test.ts` asserts a 200 with the list. Filament
puts a Dashboard there by default.

Resolved by giving `AppResource` an **empty slug** (`protected static ?string
$slug = '/'`) with its non-index pages routed explicitly under `/apps`:

```
GET /                    apps index
GET /apps/create         create
GET /apps/{record}       view
GET /apps/{record}/edit  edit
GET /deployments         deployments index
GET /deployments/{record} deployment view
GET /settings            settings
```

— byte-identical to the Express app's shape, so bookmarks and the documented
webhook URL keep working. Two consequences to know:

- **`Dashboard::class` is deliberately unregistered** in `AdminPanelProvider`,
  along with the two default dashboard widgets. Registering it puts a second
  page on `/` and shadows the app list. `->pages([])` and `->widgets([])` are
  intentional, not leftovers.
- The generated route names carry a **doubled dot**
  (`filament.admin.resources..index`). Harmless — nothing resolves these by
  literal name — but do not "tidy" the slug without re-checking every URL
  above.

### The lifecycle side effects live in `App\Services\AppProvisioner`

Create, update and delete are not CRUD, and the parts that are not CRUD are in
a service rather than inline in the pages: the create page and BOTH delete
actions (edit-page header and apps-table row) are separate callers of the same
rules, and every message is a parity contract that needs pinning without
standing up a Livewire component.

- **Create** — `CreateApp::handleRecordCreation()` resolves the relative path
  segment to an absolute one, runs `provision()` (clone unless importing, then
  seed `.env`), and only then writes the row. Order matters: mutation
  `C3-creates-row-before-provisioning` — creating the row first — is killed by
  four tests, because a failed clone would otherwise leave an app row whose
  checkout does not exist.
- **Clone failure is a form error, not a 500.** `provision()` throws
  `App\Exceptions\CloneFailed` whose message is ALREADY the user-facing
  `Clone failed: …` string, and the page converts it to a
  `ValidationException` on `data.repo_url`. The dedicated exception type
  exists so a clone failure is distinguishable from any other
  `RuntimeException` escaping provisioning, which should stay a real error.
- **Delete** — `destroy()` runs `docker compose down` and then removes the
  directory, both **before** the row goes. Every step is best-effort and
  nothing may throw: losing the DB row while the containers keep running is
  the worse failure. The 60s bound is a **wall-clock** `timeout:`, not the
  idle timeout `DeployApp` uses — `docker compose down` printing something
  every 59 seconds forever must still be killed, unlike a legitimately long
  build. Mutating it to `idleTimeout:` is killed.
- **No `DeleteBulkAction` on the apps table.** Bulk delete would run an
  unbounded series of `docker compose down` calls, each with its own 60s
  ceiling, inside one web request.

### `path` means two different things, by operation

The reference has two forms with two rule sets, and this port has one schema
serving both:

- **create** — a RELATIVE segment, prefixed with `BRIDGE_REPOS_PATH`, max 255,
  subject to the directory-state rules;
- **edit** — the FULL stored path, max 500, no directory-state rules (the
  checkout exists; the operator may be repointing it).

The `..` traversal rule is **unconditional**, unlike the reference, which
checks it on create but not update (`appValidators.ts:87`) — one of the
plan's "fix, do not carry forward" defects. Mutation
`F2-traversal-rule-create-only` restores the reference's behaviour and is
killed by `test_a_parent_directory_traversal_is_rejected_on_update_too`.

`health_url` and the deploy-steps textarea are **edit-only**, matching the
reference: its create route reads only name/repo_url/branch/path off the
request and its `create.ejs` has neither field.

### The branch field is a Select that degrades to free text

Filament's `Select` has no custom-value affordance, and a private repo with no
key configured — or simply an offline host — must not lock the operator out of
the form. So `branch` is **two mutually exclusive components on the same state
path**: a `Select` when `GitService::lsRemote()` returns anything, a
`TextInput` when it returns nothing. A hidden Filament component is not
dehydrated (`isHiddenAndNotDehydratedWhenHidden()`), so exactly one ever
writes.

`AppForm::branches()` swallows every lsRemote failure and returns `[]`. That
empty result is the signal the fallback keys off, so the swallow is
load-bearing rather than defensive noise. Results are memoised per repo URL
for the request — `options()` and both `visible()` predicates ask the same
question on the same render, and `git ls-remote` is a network call.
`AppForm::forgetBranchCache()` is the test seam for that memo.

### Two extractions, one for sharing and one for correctness

- **`Deployment::durationText()`** — the `1m 05s` / `42s` formatting moved out
  of `SlackNotifier` so the deployments table and the Slack payload cannot
  drift. `SlackNotifierTest`'s 17 cases stayed green across the move, which is
  the evidence the extraction was faithful.
- **`SlackNotifier::sendTest()`** — unlike `notify()`, this one **throws** on
  failure. The reference swallows the error and then reports it through its
  SUCCESS flash channel (`settings.ts:31-33`); the plan lists that as a defect
  to fix. The Settings page turns the exception into a **danger**
  notification. Mutation `S1-failure-reported-as-success` restores the
  reference's behaviour and is killed by two tests.
  - The danger notification deliberately carries **no `->body($e->getMessage())`**.
    An HTTP `RequestException`'s message embeds the request URL and response
    body, which for this button means rendering the Slack webhook URL — a
    credential — into a toast.

### Rollback: which of the reference's three guards survived, and why

`reference/src/routes/apps.ts:195-211` has three. In the panel the action is
bound to a `Deployment` record, which changes what each one means:

1. `source.app_id !== app.id` → 404. **Structurally impossible here** — the
   app is read off the source deployment, so there is no second id to
   disagree with. Phase 5's `POST /apps/:id/rollback` DOES take both ids and
   **must keep this check**; it is a real authorisation boundary there.
   `test_rollback_targets_the_app_the_source_deployment_belongs_to` pins that
   the app comes from the source rather than from, say, the first app in the
   table.
2. `!source.commit_sha` → 400. Kept, as both a visibility rule and a refusal
   inside the action — hiding is presentation, and a stale page could still
   submit against a row whose SHA has since been cleared.
3. Successful deployments only. A visibility rule, matching the reference,
   whose route does not enforce it either — only its view renders the button
   on successful rows.

### `FakeProcessRunner` gained an argv-addressed mode

`answerByArgv()` switches the fake from an ordered QUEUE to a responder keyed
on the command. The queue assumes a test knows exactly how many processes will
be spawned and in what order; that holds for a job and does **not** hold for a
Livewire component, where a form listing a repo's branches re-runs
`git ls-remote` an implementation-defined number of times per interaction and
interleaves that with the `git clone` the submit handler runs. Pinning those
counts would pin Filament's render behaviour, not this app's.

Strictness is preserved rather than traded away: the responder is handed the
argv and MUST return a `ProcessResult` for it; returning null throws, exactly
like the exhausted queue does. **Use the queue for jobs and services, the
responder for Livewire components.**

### Verified by mutation

40 mutations, one at a time, full suite per mutation, restore verified by
hash. **All 40 killed.** Two survived the first pass and both turned out to be
**dead defensive code**, now removed rather than papered over with a test:

- **`health_url`'s `?: null` coercion in `handleRecordUpdate()`.** Filament
  already dehydrates a blank `TextInput` to NULL — confirmed by direct probe,
  not just by the surviving mutation. The behaviour still matters (an empty
  string is a URL the health poller would dutifully poll) and is now pinned
  through the mechanism that actually implements it: mutation
  `E5b-blank-health-url-dehydrates-as-empty-string` forces a blank to
  dehydrate as `''` and is killed.
- **`trim()` in `Settings::save()`.** The field's own `->trim()` had already
  run. Note the ordering there: `->trim()` must come **before** `->url()`, or
  a URL pasted with a trailing newline is rejected as malformed instead of
  being cleaned up. Mutation `S2b-field-loses-trim` is killed.

Both cases are the same lesson as Phase 3's round-2 findings: a mutation
surviving is not automatically a missing test — it can be code that never had
an effect.

Killed and worth recording:

- `P1-swap-skipclone-branches` and `P2-swap-import-messages` — the import and
  clone branches enforce **opposite** rules ("must exist" vs "must not"), so
  their messages must not be interchangeable.
- `P5-env-seed-overwrites` — dropping the `! exists($env)` guard, i.e.
  clobbering an operator's real `.env` with `.env.example`.
- `P7`/`P9` — leaving the checkout on disk, and letting a `docker` failure
  escape `destroy()`.
- `A5-webhook-secret-halves-in-width` — 16 random bytes instead of 32. The
  width is the HMAC key strength Phase 5's signature check depends on.
- `A6-env-editor-accepts-empty-content` — the `.env` editor's empty-content
  refusal, which is only worth anything because the file survives it.
- `D1-short-sha-is-eight-chars` and `D2-duration-minute-boundary-off-by-one`
  — the two presentation contracts that are actually contracts.

### For Phase 5 (API/webhook) and Phase 6 (log viewer)

- **`DeploymentInfolist`'s Log section is a placeholder.** It renders the
  stored `log` as-is, which is correct for a finished deployment and merely
  stale for a running one. Phase 6 replaces that entry with the polling
  Livewire component; the `.lcars-log` class is already on it.
- **`POST /apps/{id}/rollback` must re-add the cross-app 404** — see the
  Rollback section above. It is the one guard the panel does not need and the
  API does.
- **`DeployAction::queue(App $app): Deployment`** is public and is the single
  place a deploy is enqueued from the UI. Phase 5's API deploy endpoint and
  the webhook should call it rather than re-creating the row and dispatching
  by hand, so "create pending row, then dispatch its id" stays in one place.
- **Nothing in this phase registered a route in `routes/web.php`.** That file
  is still empty of routes and its precedence warnings still apply verbatim.

## Phase 5 — API, webhook, JSON endpoints

`php artisan test` — **393 tests, 1081 assertions, all green**, in fixed order
and under random-order seeds 1, 4242 and 31337. Measured breakdown of the 110
added this phase:

| | tests |
|---|---|
| `tests/Feature/ParityRoutesTest` | 36 |
| `tests/Feature/Api/ApiDeployTest` | 29 |
| `tests/Feature/WebhooksTest` | 11 |
| `tests/Unit/Http/Middleware/RequireApiTokenTest` | 8 |
| `tests/Unit/Services/ApiTokenResolverTest` | 8 |
| `tests/Feature/Api/ApiBranchesTest` | 7 |
| `tests/Feature/Api/ApiOpenApiTest` | 5 |
| `tests/Unit/PollHealthChecksTest` | 5 |
| `tests/Feature/PollHealthCommandTest` | 1 |

### There are now four route files, and which one a route goes in is a decision

`routes/web.php` is still empty of routes. The other three exist because a
route's file determines its middleware, and for this phase that is load-bearing
rather than cosmetic:

| File | Loaded by | Prefix | Middleware |
|---|---|---|---|
| `web.php` | `withRouting(web:)` | none | `web` — session, CSRF |
| `api.php` | `withRouting(api:)` | `/api` | `api` + `api.token` |
| `webhook.php` | `withRouting(then:)`, bare `require` | none | **none** |
| `parity.php` | `withRouting(then:)`, bare `require` | none | `api.token` |

The `then:` closure runs after web/api/health are registered, and a bare
`require` inside it is what produces a route with no group at all. Wrapping it
in `Route::middleware('web')->group(...)` — the shape most examples show —
would silently reintroduce CSRF.

The webhook needs *both* exclusions and that is why it cannot live in either
existing file: `web.php` gives it `PreventRequestForgery` and a 419 on every
GitHub push, and `api.php` gives it a `/api` prefix its URL cannot have.

### Fail-closed auth, and the one thing the reference gets away with

`App\Services\ApiTokenResolver` layers `BRIDGE_API_TOKEN` over the `api_token`
settings row, trimming both, and returns null when neither yields anything.
`App\Http\Middleware\RequireApiToken` turns null into **503 "API token not
configured"**. Empty does not mean public, and mutation
`M1-fail-open-when-unconfigured` (pass through instead of 503) is killed.

**The JSON error key is `error`, not `message`.** Laravel's own JSON error
rendering uses `message`, and `bootstrap/app.php` declares
`shouldRenderJsonWhen($request->is('api/*'))` — so anything produced by
`abort(404)` has the wrong shape for this API. Every payload in this phase is
built explicitly with `response()->json()`. `bridge-mcp/bridge_mcp/client.py`
reads `body["error"]` and maps 503 to its own `BridgeApiConfigError`;
mutation `M4-error-key-becomes-message` is killed.

### Token comparison needs an equal-length wrong token to be tested at all

`hash_equals()` guards both the bearer token and the webhook signature. The
first QC pass found that every wrong-token test used a wrong token of a
*different length* to the real one, so replacing `hash_equals($expected,
$provided)` with a bare `strlen() === strlen()` left the whole suite green —
i.e. any 9-character token would have authenticated against a 9-character
secret. `test_returns_401_when_the_wrong_token_is_the_same_length_as_the_real_one`
is the test that closes it. Mutation `M2-length-only-token-compare` is killed.

The webhook's own `hash_equals` → `===` mutation (`W9`) **survives and is an
equivalent mutant**: the two are behaviourally identical and differ only in
constant-time execution, which no functional test can observe. Recorded rather
than papered over with a test that would not actually discriminate. Do not
"fix" it by relaxing the comparison — the timing property is the reason it is
there.

### Webhook parity details that look like bugs and are not

- **HMAC is over `$request->getContent()`**, the raw bytes. Re-encoding a
  decoded array changes them and every signature fails
  (`W3-hmac-over-reencoded-body`, killed).
- The compared string **includes the `sha256=` prefix** (`W2`, killed).
- **A validly-signed request with a non-JSON body deploys.** The reference
  catches the parse error, leaves the payload `{}`, finds no `ref`, and falls
  through to enqueue. Ported as-is, pinned by
  `test_non_json_body_with_valid_signature_deploys_anyway`. This is deliberate
  reference behaviour, not an oversight to tighten into a 400.
- A missing `ref` deploys; only an *extracted and different* branch skips.

### `DeployAction::queue()` is now genuinely the only enqueue path

Phase 4 left `RollbackAction` creating its own pending row and dispatching by
hand, because `queue()` took no `rollback_sha`. The signature widened to
`queue(App $app, ?string $rollbackSha = null)` and `RollbackAction` now
delegates. Four callers, one implementation: the panel's deploy action, the
rollback action, the webhook, and both deploy endpoints. Phase 4's rollback
tests passed unmodified across the change, which is the evidence the
delegation is faithful.

### The parity endpoints are authenticated, and the reference is not

`GET`/`POST /apps/{id}/env`, `GET /apps/{id}/containers`,
`POST /apps/{id}/deploy` and `POST /apps/{id}/rollback` carry `api.token`.
**Decided with the user, not defaulted into.**

The reference has no authentication anywhere — it was a trusted-network Express
app. Porting that faithfully would mean an unauthenticated `POST
/apps/{id}/env` writing arbitrary content into a production app's `.env`, and a
`GET` reading those secrets back, for anyone who can reach the host. Bearer
auth keeps the URLs byte-identical, avoids the CSRF problem the `web` group
would create for script callers, and puts a credential in front of the secret
material. Mutation `P5-parity-routes-lose-auth` is killed.

Consequences to know:

- Every response is JSON, including the two paths where the reference sends
  plain text (`res.status(404).send('Not found')`) and the two where it
  redirects to `/deployments/:id`. There is no EJS page for a token-authenticated
  machine caller to land on. Deploy and rollback both return **202** with
  `{deployment_id, app_id, status}`, matching `POST /api/apps/{id}/deploy`.
- **`POST /apps/{id}/rollback` re-adds the cross-app 404** that the panel does
  not need — the one guard Phase 4 explicitly deferred here. The panel's action
  is bound to a Deployment and reads the app off it; this endpoint takes both
  ids, so without the check a caller rolls app A back to app B's commit.
  `P1-cross-app-rollback-check-removed` is killed by
  `test_rollback_returns_404_when_the_deployment_belongs_to_a_different_app`.
- It does **not** restrict to successful source deployments. The reference's
  route does not either — only its view hides the button — matching Phase 4's
  decision to keep that a visibility rule.

### A real bug this phase introduced and caught: global `TrimStrings`

Laravel 13 moved `TrimStrings` and `ConvertEmptyStringsToNull` into the
**global** middleware stack; earlier versions scoped `TrimStrings` to the `web`
group. So it ran on the env-write endpoint despite that endpoint being outside
`web` entirely, silently stripping the trailing newline a `.env` legitimately
ends with — something the reference's `writeFileSync(..., content, 'utf-8')`
never does.

Fixed with `$middleware->trimStrings(except: ['content'])` in
`bootstrap/app.php`. Mutation `T1-trimstrings-exception-reverted` is killed.

**Why the existing Phase 4 tests could not have caught this:** the Filament
modal is exercised with `Livewire::test()->callAction()`, which never crosses
the HTTP kernel, so no global middleware runs. Any future test that asserts on
exact request-body bytes needs to go through the HTTP layer to be meaningful.

### The health poller finally has an invocation mechanism

`App\Jobs\PollHealthChecks` calls `HealthPoller::pollDue()` and re-dispatches
itself with a 60s delay. `bridge:poll-health` (`App\Console\Commands\PollHealth`)
kicks off the first one. This is the mechanism `HealthPoller`'s own Phase 2
docstring specified — a queued job on the existing worker, not a scheduled
command (needs cron or `schedule:work`, a third process) and not a bespoke loop
(also a third process).

Three things that must not drift:

- **The re-dispatch lives in `finally`.** There is no cron backstop, so a throw
  escaping `handle()` is not a degraded pass, it is a permanent outage.
  `H2-reschedule-only-on-throw` and `H1-poller-stops-rescheduling` are both
  killed.
- **It stays on the default queue, and so does `DeployApp`.** Phase 7's
  supervisord runs one `queue:work` with no `--queue` flag, which consumes
  `default` only. Naming a queue here would mean all health polling silently
  never runs while every test stayed green — which is exactly what mutation
  `H3-poller-on-named-queue` demonstrated by surviving the first QC pass.
  `test_handle_reschedules_itself_onto_the_default_queue` closes it.
- **`QUEUE_CONNECTION` is `sync` in `phpunit.xml`, so this job must never be
  really dispatched in a test** — `delay()` is ignored under `sync` and the
  self-re-dispatch recurses without bound. Every test calls
  `app()->call([new PollHealthChecks, 'handle'])` under `Bus::fake()`.

One ordering consequence for Phase 7: a single worker means a long deploy
blocks the health tick for its full duration. Not a bug — `HealthCheck` rows
are additive, so the poller catches up on the next tick — but it is real.

### Verified by mutation

**68 mutations across two rounds**, one at a time, full suite per mutation,
restore verified by hash with the restore wired to a signal handler as well as
`finally`. **62 killed on first application, 6 survived**, of which four were
genuine coverage gaps (now killed by four added tests) and two are equivalent
mutants.

Round 1 — 44 mutations, 41 killed. Survivors:

- **`M2-length-only-token-compare`** — *missing test*, covered above.
- **`H3-poller-on-named-queue`** — *missing test*, covered above.
- **`W9-hash-equals-to-strict-compare`** — *equivalent mutant*, covered above.

Round 2 — 24 mutations aimed where round 1 had not looked (route-level
middleware, the OpenAPI document, resolver precedence, cross-endpoint
consistency). 21 killed. Survivors:

- **`V1-webhook-hardcoded-branch`** — *missing test*, and the most valuable
  find of the phase. Replacing `$pushedBranch !== $app->branch` with a
  hardcoded `!== 'main'` left the entire suite green, because every webhook
  test used an app tracking `main`. Any app on another branch would have
  ignored its own pushes and deployed on someone else's.
  `test_branch_comparison_uses_the_apps_own_branch_not_a_hardcoded_main` uses
  an app tracking `develop` and asserts both directions.
  **This is the same failure shape as Phase 3's `commit_sha`/`commit_message`
  swap**: a fixture whose values coincide cannot distinguish "reads the right
  field" from "reads a constant that happens to match."
- **`K3-api-deploy-injects-rollback-sha`** — *missing test*. `queue($app,
  'deadbeef')` on the API deploy endpoint survived: nothing asserted that a
  plain deploy leaves `rollback_sha` null, so a plain deploy silently becoming
  a rollback to an arbitrary commit was invisible. `ParityRoutesTest`'s deploy
  case already had the assertion; `ApiDeployTest`'s did not, which is exactly
  why the mutation survived on one endpoint and would have died on the other.
  **When two endpoints share an enqueue path, assert the same invariants on
  both** — the shared implementation is what makes it tempting not to.
- **`G3-apps-status-enum-not-value`** — *equivalent mutant*. Dropping `->value`
  from `'status' => $app->status->value` changes nothing observable: PHP backed
  enums are `JsonSerializable` to their value, verified directly
  (`json_encode(['status' => S::Idle])` is byte-identical to
  `json_encode(['status' => S::Idle->value])`). Kept anyway — it is an explicit
  projection rather than a redundant guard, and the exact-JSON assertions
  already pin the behaviour. Not deleted as dead code, because unlike Phase 4's
  two cases this line is not a guard that never fires.

The harness is worth rebuilding for Phase 6 rather than reinventing: a JSON
list of `{id, file, find, replace}`, an exactly-once match check that refuses
to apply an ambiguous mutation, and a hash-verified restore. The
match-count check earned its keep — an ambiguous `find` silently mutating the
wrong occurrence is a false "killed".

**Run the harness from the main session, not a subagent.** The Phase 5 QC
subagent stalled out (no progress for 600s) having spent its context just
reading the diff, without applying a single mutation. Driving the script
directly worked first time; 68 mutations at ~10s per suite is about 12 minutes
of wall clock.

### The `bridge-mcp` consumer check, run for real

Plan verification item #5 — "point `bridge-mcp/` at the Laravel API and confirm
its five tools still work" — **has now actually been run**, not just reasoned
about. All five client calls pass against a live server, driving the real
`bridge_mcp.client` module rather than a reimplementation of its requests.

How to reproduce it (worth keeping — Phase 7 will want it again):

- Temp SQLite file, `php artisan migrate`, seed one app and one deployment.
- **Do not use `php artisan serve`.** It forwards only a fixed allowlist of env
  vars to its child process (`APP_ENV`, `XDEBUG_*`, and a few more), so
  `DB_DATABASE` is silently dropped and the server reads the real dev database
  instead. This cost a full debug cycle. Use
  `php -S 127.0.0.1:8765 -t public public/index.php` with the env inline.
- Leave `BRIDGE_API_TOKEN` empty and seed the `api_token` settings row instead
   — that exercises the settings fallback layer, which is how operators
  actually configure it.
- `QUEUE_CONNECTION=database` with no worker running: a deploy call writes its
  job row and returns 202 without executing a real `git clone`. Confirmed
  afterwards — two queued jobs, deployments left `pending`, nothing ran.

What it proved beyond the five calls: the incremental-tail polling pattern
Phase 6 depends on (pass the previous `X-Log-Offset` back, get an empty slice
and an unchanged offset), that `X-Log-Offset` is the full log length rather
than the slice's when reading from a mid-log offset, and that 404 and 401 both
surface as `BridgeApiError` reading the `error` key. The initial misconfigured
run incidentally proved the 503 path too: it raised `BridgeApiConfigError`,
the distinct type the client reserves for an unconfigured token.

### Parity acceptance for this phase

All **21** reference cases across `apiDeploy`, `apiOpenapi`, `webhooks`,
`containerStatus`, `envEditor` and `rollback` map to ported tests, with 89
additional cases beyond them (auth ladders on every route, non-numeric ids,
offset edges, the cross-app rollback boundary).

Two reference behaviours deliberately not carried: the rollback/deploy
redirects (now 202 JSON, see above) and `/branches`'s `repo_url` being marked
`required: true` in the OpenAPI spec while the endpoint explicitly handles its
absence — the spec was wrong and is now `required: false`, pinned by
`test_openapi_json_marks_branches_repo_url_parameter_as_optional`.

### Corrections to earlier notes

- The Phase 1 note that "the API and webhook app-creation endpoints bypass the
  form and need their own explicit `catch` for the `apps.path` constraint
  violation" (see the `apps.path` bullet in Phase 1) **describes endpoints that
  do not exist**. `reference/src/routes/api.ts` has no app-creation route and
  neither does the webhook; the only way to create an app is the Filament form.
  Nothing was built for it and nothing needs to be.

### For Phase 6 (log viewer) and Phase 7 (packaging)

- **Log offsets are BYTES** (`strlen`/`substr`), not UTF-16 code units. Phase 1
  flagged this as a unit Phase 5 had to pick and be internally consistent
  about; this is that decision. `X-Log-Offset` is the **full** log length, not
  the returned slice's — that is what makes incremental polling work, and
  `D1-log-offset-is-slice-length` is killed.
- `X-Deploy-Done` is the literal string `"true"`/`"false"`, not a JSON boolean.
  `bridge-mcp` compares against the string.
- **There is no throttle on `/api/*`.** Laravel only adds `throttle:` to the
  `api` group when `$middleware->throttleApi()` is called, and it is not.
  Deliberate: the default is 60/min and Phase 6's `wire:poll.1s` log viewer is
  exactly 60/min, so it would start 429ing under any jitter. Do not add
  `throttleApi()` without giving the log endpoint its own much higher limit.
- **`bridge:poll-health` has no duplicate-kickoff guard.** Two invocations mean
  two chains ticking forever. A lease-renewed lock spanning an indefinite job
  chain would, when stale, block the only manual recovery path, so it is an
  entrypoint obligation instead: **Phase 7 must invoke it exactly once per
  container start**, the same guarantee the entrypoint already owes migrations
  and `bridge:reset-stuck-deployments`. Worst case of a double kickoff is
  doubled request volume and duplicate `HealthCheck` rows — additive, not
  corrupting.
- **The Docker socket is NOT at `/var/run/docker.sock` on this machine.** Docker
  Desktop on macOS puts it at `~/.docker/run/docker.sock`
  (`docker context inspect --format '{{.Endpoints.docker.Host}}'` confirms).
  The plan's Phase 7 volume mount assumes the Linux path. Read the context
  rather than hardcoding, or the socket mount silently binds a non-existent
  path and every Docker call fails on a host that looks fine. Verified
  2026-08-01 with Docker 29.6.2 / Compose v5.3.1.
- **A live deploy target already exists** at
  `/Users/aclinton/Dev/Personal/Docker/bridge-test-app` — real git repo on
  `main`, `nginx:alpine` compose on port 8099, static page for `health_url`,
  and a `bridge.yml` post-deploy step deliberately spread over ~5 seconds so
  incremental log polling is observable instead of arriving in one burst.
  Verified end to end (clone, pull, up, HTTP 200, `exec -T` post-deploy,
  teardown); `nginx:alpine` is pre-pulled. Point an App row at it with
  `repo_url` set to that path, run a real `queue:work` (the app's
  `QUEUE_CONNECTION` is `database`, not `sync`), and deploy.
- `tests/Feature/ParityRoutesTest::test_env_get_returns_500_when_the_file_cannot_be_read`
  uses `chmod(0000)` and **self-skips as root**. It runs locally; in a
  root-running CI container it would silently skip. Verified running here.
- The pre-existing `vendor/bin/pint --test` failure on `bootstrap/providers.php`
  is untouched by this phase and still there.

## Phase 6 — deploy log viewer

`php artisan test` — **421 tests, 1149 assertions, all green**, in fixed order
and under random-order seeds 1, 4242 and 31337. 28 added this phase:
`tests/Feature/Filament/DeploymentLogTest` (18) and
`tests/Feature/Filament/ResetDeploymentTest` (10). `vendor/bin/pint --test`
still fails only on the pre-existing `bootstrap/providers.php`.

Livewire is **v4.3.4** here (Filament 5.7.4 requires `^4.1`), not the v3 most
snippets assume. Everything below was read out of `vendor/livewire/livewire/`
rather than recalled.

### The viewer does not call the log endpoint, and could not

Phase 5's note said Phase 6 "polls it at `wire:poll.1s`", and the comment at the
top of `routes/api.php` still argues against `throttleApi()` on that basis. The
premise is wrong, and the conclusion happens to survive anyway:

`GET /api/deployments/{id}/log` is behind `api.token`. A panel session has no
bearer token to hand JavaScript, so a browser `fetch()` to that route is a 401.
`App\Livewire\DeploymentLog` reads the database directly instead, and
`wire:poll` targets `/livewire/update`, not `/api/*`.

What keeps the two paths honest is a shared pair of model methods —
`Deployment::logLength()` and `Deployment::logSlice()` — which the controller
now uses too. Offsets mean the same bytes on both, which is what Phase 1's
"pick one unit" note actually asked for.

**The `throttleApi()` warning still stands**, for a different reason: nothing
polls `/api/*` from this app, so a limiter there would only ever throttle
`bridge-mcp` and other external consumers, silently. Leave it off.

### Three things the component does that are not obvious

- **The log is never component state.** Every public Livewire property is
  serialised into the snapshot and sent BACK to the server on each poll; a
  public `$log` would ship a multi-megabyte build log over the wire twice a
  second. Only `$offset`, `$status` and `$done` are public. New output is
  dispatched as a browser event (`deployment-log-appended`) and appended by
  Alpine.
- **`wire:ignore` on the `<pre>` is load-bearing.** It hands the box's contents
  to the browser permanently. Without it the next morph replaces the box with
  what the server last rendered — the log as it stood at MOUNT — silently
  discarding every appended chunk. Nothing in PHPUnit can catch its removal;
  see "What the suite cannot cover" below.
- **Polling stops by dropping the attribute.** `wire-poll.js` pauses when
  `wire:poll` is no longer on the element (`theDirectiveIsOffTheElement`), so
  the view renders it only while `$done` is false. There is no JS stop API to
  call. Verified in the browser, not just in the rendered HTML: zero
  `/livewire/update` requests in the 4 seconds after a deploy finished.

`poll()` reads the chunk **before** it sets `$done`. Getting that order wrong
truncates the log permanently at the second-to-last poll, because nothing polls
again to pick up what the worker wrote alongside the final status —
`test_the_last_chunk_is_delivered_by_the_same_poll_that_stops_polling` pins it
and mutation `M5` is killed by exactly that one test.

### The rest of the page had to learn to move too

Only the log box updates on its own; the infolist's status badge, duration and
`finished_at`, and which header actions are offered, are all rendered once at
page load. So `DeploymentLog::poll()` dispatches `deployment-status-changed` on
any status transition (not only on completion — `pending` → `running` matters
just as much), and `ViewDeployment` listens with `#[On]` and re-renders.

That is what makes the Reset button vanish when a deploy ends, which is the
reference's `x-show="!done"` (show.ejs:16). The dispatch is deliberately
conditional: firing every second would re-render the whole page around the log
for the entire length of a build.

Both halves are pinned, because a rename on either side leaves a live log under
a permanently stale badge with nothing failing: `assertDispatched` on the
component, and `SupportEvents::getListenerEventNames()` on the page.

### Reset: `visible()` is the whole fix, and the second guard was removed

The plan lists the reference's unconditional success flash as a defect —
`reference/src/routes/deployments.ts:68` flashes `Deployment reset to failed.`
outside its own `if (resetable)` block, so clicking Reset on a deployment that
finished a moment ago reports a state change that did not happen. On a page
that polls a live deploy that window is real, not theoretical.

`ResetDeploymentAction` was first written with a belt-and-braces re-read inside
the action, mirroring `RollbackAction`'s `commit_sha` guard, reporting a warning
notification on a no-op. **It could not fire, and was removed.** Filament
evaluates `visible()` against a freshly-loaded record at BOTH `mountAction()`
and `callMountedAction()` (`Filament\Actions\Concerns\InteractsWithActions:158`
and `:244` — a hidden action is disabled, and a disabled action unmounts without
running). Confirmed empirically first, not by reading alone: the test asserting
that warning failed with `A notification was not sent`. The confirmation modal
does not open a window either, since the second check happens after it.

Kept instead: a test on the mechanism that actually implements the fix — a
deployment that finished since the page rendered is neither reset nor reported
as reset (mutation `M18`, deleting `visible()`, is killed by four tests). Same
lesson as Phase 4's two removals: a mutation surviving can mean dead code, and
dead defensive code gets deleted rather than papered over with a test.

**This applies to `RollbackAction` too, and it was left alone.** Its docblock's
claim that "a stale page could still submit against a row whose SHA has since
been cleared" is now known to be false — Filament stops that before the closure
runs. The code is harmless and removing it is outside this phase; **Phase 8's
final QC should either delete that guard or correct the docblock.**

The cost of having no in-action refusal is that a stale click does nothing
silently. It is small: the status-changed dispatch above removes the button
within a second of the deploy ending.

### Verified against a real deploy, which is the phase's exit criterion

Deployed `/Users/aclinton/Dev/Personal/Docker/bridge-test-app` (the target Phase
5 left ready) through the panel against a real `queue:work`, driving Chrome:

- output appeared incrementally over ~11s, not in one burst at the end;
- status flipped `running` → `success` with no manual refresh, and Commit,
  Message, Duration and Finished at filled in with it;
- the poll attribute left the DOM and `/livewire/update` traffic went to zero;
- on a long log, the box was already scrolled to the bottom on load
  (`scrollTop` 3088 of 3688) and stayed pinned as chunks arrived;
- a chunk containing `→ ünïcode ✓` appended intact with no duplication of
  earlier content — the byte offsets survive a multibyte boundary in practice,
  not only in `test_offsets_are_bytes_...`;
- Reset on a stuck `running` deployment produced `Deployment reset to failed.`,
  status `failed`, a stamped `finished_at` (duration `20m 37s`), the app back to
  `failed`, and the button gone;
- no console errors throughout.

### What the suite cannot cover

Everything above the PHP boundary is browser behaviour, and PHPUnit sees only
the rendered HTML. Specifically unpinned by tests:

- removing `wire:ignore` (the log box would reset to its mount-time contents on
  the next morph);
- `textContent` → `innerHTML` in `append()`, which would execute build output as
  markup. `test_the_log_is_rendered_as_text_not_html` covers the server-rendered
  half only;
- the auto-scroll and the placeholder-replacement branch in `append()`.

A Dusk suite would close these. Until there is one, **re-run the real-deploy
check above after touching `resources/views/livewire/deployment-log.blade.php`**
— it exercises all three in about a minute.

### State at handoff

Phase 6 is complete. Nothing is left half-built, and no Phase 7 work was
started.

| | |
|---|---|
| Branch | `laravel-port`, worktree `../the-bridge-laravel` |
| Suite | 421 tests / 1149 assertions, green (fixed + seeds 1, 4242, 31337) |
| Pint | fails only on the pre-existing `bootstrap/providers.php` |
| Phase 6 changes | **uncommitted** at the time of writing |

Touched by this phase — 5 modified, 5 new:

```
M app/Enums/DeploymentStatus.php                                  isTerminal/isResettable
M app/Models/Deployment.php                                       logLength/logSlice
M app/Http/Controllers/Api/DeploymentsController.php              uses both
M app/Filament/Resources/Deployments/Schemas/DeploymentInfolist.php   TextEntry → Livewire
M app/Filament/Resources/Deployments/Pages/ViewDeployment.php     Reset action + #[On]
+ app/Livewire/DeploymentLog.php
+ resources/views/livewire/deployment-log.blade.php
+ app/Filament/Actions/ResetDeploymentAction.php
+ tests/Feature/Filament/DeploymentLogTest.php
+ tests/Feature/Filament/ResetDeploymentTest.php
```

**The local environment was returned to empty afterwards** — `apps`,
`deployments` and `health_checks` rows deleted, `repos/bridge-test-app`
removed, `docker compose down` run. Phase 7 starts from an empty local database
and must re-provision before it can deploy anything.

### Re-running the manual check (Phase 7 will need this repeatedly)

The exact sequence Phase 6 used, from a clean tree:

```bash
php artisan migrate --force
php artisan tinker --execute='
$p = app(App\Services\AppProvisioner::class);
$full = App\Services\AppProvisioner::fullPath("bridge-test-app");
$p->provision($full, "/Users/aclinton/Dev/Personal/Docker/bridge-test-app", "main", false);
App\Models\App::create(["name"=>"Bridge Test App","repo_url"=>"/Users/aclinton/Dev/Personal/Docker/bridge-test-app","branch"=>"main","path"=>$full,"health_url"=>"http://localhost:8099/","status"=>App\Enums\AppStatus::Idle]);'
php artisan serve --port=8123 &
php artisan queue:work &
```

(No `--timeout` needed — `DeployApp::$timeout` wins; see the Phase 7 notes
below.)

Then log in at `http://127.0.0.1:8123/login` with `BRIDGE_ADMIN_EMAIL` /
`BRIDGE_ADMIN_PASSWORD` from `.env` and press Deploy. A full cycle (pull, pull
image, down, up --build, 5 post-deploy steps) takes about 11 seconds.

Clean up after with `docker compose down` inside the checkout, deleting the
rows, and `rm -rf repos/bridge-test-app` — a leftover `running` row makes the
next `bridge:reset-stuck-deployments` test ambiguous.

### For Phase 7 (packaging) and Phase 8 (cleanup)

- **The job timeout is already handled; `retry_after` is the one that is not.**
  `DeployApp`'s docblock used to say Phase 7's `queue:work` must ALSO pass
  `--timeout=0` or the worker's shorter timeout would win. That is wrong and
  the docblock is now corrected: `Worker::timeoutForJob()` is
  `$job->timeout() !== null ? $job->timeout() : $options->timeout`, and the
  property rides into the payload via `Queue::createPayloadArray()`, so
  `$timeout = 0` beats the flag. Passing `--timeout=0` anyway is harmless.

  What is genuinely unresolved is `config/queue.php`'s
  `'retry_after' => env('DB_QUEUE_RETRY_AFTER', 90)`. It is not a kill switch —
  it makes a reserved job visible again 90 seconds in, which with **exactly one
  worker** cannot bite (the job row is deleted when `handle()` returns, before
  that worker polls again). It becomes a duplicate-deploy bug the moment
  anything runs a second worker. Phase 7 keeps concurrency at 1, so the safe
  move is to write that dependency down next to the supervisord config rather
  than leave the 90 as an unexamined default.

  Neither of these shows up in an 11-second test deploy. Do not treat a green
  smoke test as proof the worker is configured for a 40-minute build.
- **Livewire's own JS needs no build step.** It is served from `vendor/` through
  Livewire's asset route (`Mechanisms\FrontendAssets`), not through Vite. The
  Dockerfile's Node build stage is still required for the Filament theme
  (`resources/css/filament/admin/theme.css`), unchanged from Phase 0 — Phase 6
  added no new Vite input.
- **`wire:poll` throttles itself in a background tab** — Livewire skips ~95% of
  ticks unless the directive carries `.keep-alive`. A backgrounded log page
  therefore updates every ~20s, then catches up in one chunk when refocused.
  Deliberate (it is the same reason `.keep-alive` exists) and a behaviour change
  from the reference, whose `EventSource` streamed regardless. Nothing is lost —
  the next poll always slices from the stored offset. Worth knowing before
  someone reports the log viewer as "stuck" while watching another tab.
- **The log viewer is the app's only sustained request load**, one request per
  second per open deployment page, each doing a `SELECT` on the deployments row.
  It is what makes the web server's concurrency setting matter at all in Phase
  7: a single-worker PHP server blocks the whole panel behind an open log page.
- **Phase 8's cleanup list should now also drop the SSE machinery** it inherits:
  `reference/` goes wholesale, but check nothing outside it still points at
  `/deployments/:id/stream`.
- **Phase 8 owes `RollbackAction` a decision** — its in-action `commit_sha`
  guard is unreachable for the reason established above, and its docblock
  asserts a stale-submit path that Filament does not allow. Delete the guard or
  correct the docblock; do not leave the claim standing.
- `app/Livewire/` is new — the first non-Filament Livewire component in the
  port. `resources/views/livewire/` sits in Livewire v4's
  `component_locations`, but the component is class-based and resolved by class
  string, so nothing tries to read that Blade file as a single-file component.

## Phase 7 — packaging

Files: `docker/{Dockerfile,Caddyfile,entrypoint.sh,supervisord.conf,php.ini}`,
`docker-compose.yml`, `.dockerignore`, plus `bridge.sh` and `Makefile` restored
to the repository root. One application change (`PollHealthChecks`) and one
test file (`tests/Feature/Packaging/PackagingConfigTest.php`).

### The web tier is FrankenPHP, not nginx + php-fpm

Classic mode. **No application code changed for it** — FrankenPHP is a PHP SAPI
embedded in Caddy, and each request still boots the framework exactly as
php-fpm would.

The reason is operational, not throughput: one supervisord program and one
30-line Caddyfile, versus three programs and three configs (nginx site, fpm
pool, and the socket wiring between them) plus `pm.max_children` guesswork
against the log viewer's steady 1 request/second/tab. Performance between the
two is a wash at this scale and nobody should pick either for speed here.

**Do not turn on worker mode.** `FRANKENPHP_CONFIG="worker ./public/index.php"`
keeps the application booted between requests like Octane; every singleton and
static in the app then outlives a request, and nothing in this port has been
written or reviewed against that. The Caddyfile says so at the top.

Costs accepted, recorded so nobody rediscovers them as surprises: PHP is a ZTS
build, and the community answer volume for a 2am Caddyfile problem is much
thinner than for nginx. Reverting is contained — the Dockerfile, the Caddyfile
becoming an nginx conf plus an fpm pool, and two more supervisord blocks. No
application code either way, in both directions.

### The Phase 3 queue decision was reversed, deliberately

`PollHealthChecks` now runs on its own `health` queue and has its own worker.
Phase 3 said it should share `default`, and its own docblock deferred the
question to "a Phase 7 decision to make deliberately". This is that decision.

What forced it: one worker runs one job at a time, so on a shared queue a
40-minute `docker compose up --build` blocks the health tick for 40 minutes —
no health checks, and **no Slack down-alert for any other app**, for the whole
build. The reference has no such stall; its poller lives in the web process.
That is a parity regression, not a latency one.

The fix is a second **single-slot** worker on a different queue, never a second
worker on `default`. `retry_after` (90s) makes a reserved job visible again
mid-run; that is harmless only while the one worker holding it is the only one
that could take it. A second `default` worker turns every deploy longer than 90
seconds into a duplicate deploy. Splitting by queue name keeps that invariant
exactly intact — which is why `retry_after` still does not need changing, and
why the note that it was "genuinely unresolved" is now discharged rather than
inherited by Phase 8.

The failure mode Phase 3 warned about is real and silent: a `--queue` in
supervisord that does not match the job's queue enqueues health ticks forever
and runs none of them, with no error anywhere.
`tests/Feature/Packaging/PackagingConfigTest.php` reads `supervisord.conf` off
disk and pins the two together, along with the other config-only invariants
nothing else in the suite would ever touch.

`--tries=1` on the health worker is also load-bearing, not tuning.
`PollHealthChecks` re-dispatches itself from a `finally`, so a tick that throws
has *already* scheduled its successor; retrying that same job forks the chain
in two, and again on every later failure.

### Entrypoint obligations, and the ordering trap inside them

`docker/entrypoint.sh` runs once per container start — before supervisord, so
a supervisord restart of a crashed worker cannot re-run any of it. That is
exactly the guarantee `PollHealth`'s docblock asks for, and it is why the
health-chain kickoff must never move into a `[program:]` block.

Order matters and is not arbitrary:

1. `REPOS_PATH`-without-`BRIDGE_REPOS_PATH` check — **fatal**, before anything
   is written. The Phase 5 note asked Phase 7 to "consider" failing loudly
   here; it does. Silently falling back to `/repos` gives a misleading "path
   not found" much later and far from the cause.
2. `BRIDGE_REPOS_PATH` exists and is writable inside the container — fatal.
   The same-absolute-path invariant is already broken if it is not.
3. `/data` + `/data/ssh` (0700) + the SQLite file.
4. `APP_KEY`: if unset, generated once into `/data/app_key` and reused. No
   baked default — a shipped key is a published key, and this container fronts
   a panel that deploys arbitrary code. Persisted rather than regenerated, or
   every operator would be logged out on every rebuild.
5. **`optimize:clear`** — this one is easy to miss. `docker compose restart`
   reuses the container filesystem, so a config cache built on the *previous*
   boot survives, and every command below would read the old environment,
   including `DB_DATABASE`. Migrating and seeding the wrong file is a quiet way
   to lose a database.
6. `migrate --force`, `db:seed --force`, `bridge:reset-stuck-deployments` —
   the reset before the web server accepts a request, so the panel never shows
   a deploy nothing is running.
7. `optimize` — safe to build here and only here, because everything it
   captures comes from an environment that is fixed for the container's life.
8. `bridge:poll-health`, exactly once.
9. `exec supervisord` — `exec` so supervisord is PID 1 and actually receives
   `docker stop`'s SIGTERM. Without it the graceful shutdown below never runs.

### The graceful-shutdown fix is real, and was measured

`deploy-worker` has `stopwaitsecs=600` and compose has `stop_grace_period: 11m`;
`stopasgroup`/`killasgroup` stay **false**, because `queue:work`'s children
*are* the deploy — signalling the group would kill `docker compose up --build`
instead of letting it finish.

Verified rather than assumed: a deploy was started, `docker compose stop` was
issued 2 seconds in, and the stop **blocked for 5.1 seconds** until the deploy
completed. The deployment row was `success`, and the next boot reported "Reset
0 stuck deployment(s)". The Express worker's `process.exit(0)` would have
abandoned it. `pcntl` is installed for this reason and is pinned by a test —
without it `queue:work` cannot trap SIGTERM at all.

### Docker environment: one pin kept, one dropped

`DOCKER_BUILDKIT=0` is kept (image `ENV`, overridable from compose). It pins
managed apps' builds to the legacy builder, which is what this application has
actually been run against. `docker-buildx-plugin` is installed anyway, so
`DOCKER_BUILDKIT=1` in compose is the whole switch.

`DOCKER_API_VERSION=1.43` is **dropped**. The Express image needed it because
Debian's `docker.io` CLI was years behind; this image installs `docker-ce-cli`
from Docker's own repository, and a current CLI negotiates the API version with
the host daemon over `/_ping`. Setting the variable *disables* that
negotiation, and would break against any future host whose minimum API version
rises above 1.43. Confirmed from inside the running container: it reports the
host's `1.55`.

Both were checked on Docker 29.6.2 / Compose v5.3.1 before deciding —
`DOCKER_BUILDKIT=0` still builds (the legacy builder is present), and
`DOCKER_API_VERSION=1.43` still connects (host `minapi=1.40`). Neither was
removed because it was broken.

### `health_url` must resolve from INSIDE the container

New, and not obvious. The health poller runs in the queue worker, so
`health_url` is fetched from the Bridge container's network namespace, not the
host's. `http://localhost:8099/` — which is what an operator reads off their
own browser — points the poller at the Bridge container itself, and every check
fails or, worse, succeeds against the wrong thing.

The Phase 6 fixture's URL had to become
`http://host.docker.internal:8099/` for the containerised run. On Linux there
is no such name by default; a managed app either needs `extra_hosts:
host.docker.internal:host-gateway`, its own container name on a shared network,
or a real hostname. Worth surfacing in the panel's help text one day; recorded
here for now.

### Smaller findings

- **Caddy was writing into `/data`.** The upstream FrankenPHP/Caddy images set
  `XDG_DATA_HOME=/data` and `XDG_CONFIG_HOME=/config` as their own volume
  convention, so Caddy's certificate store landed in the volume an operator
  backs up, next to the SQLite database and the deploy key. Both are now
  pointed at `/var/lib/bridge`. With `auto_https off` and `admin off` nothing
  in there needs to survive.
- **`BRIDGE_SSH_KEY_PATH` is deliberately not interpolated from `.env`.**
  Unlike `BRIDGE_REPOS_PATH`, which *must* be the host path, the key is only
  ever read inside the container, and a developer `.env` points it at a host
  directory that does not exist there. Hardcoded to `/data/ssh/id_rsa` in
  compose.
- **`env_file: .env` is not used and a test forbids it.** A developer `.env`
  carries `APP_ENV=local` and `APP_DEBUG=true`, and a debug-mode panel that can
  deploy arbitrary code renders stack traces containing its own configuration
  to anyone who reaches a 500.
- **Compose reads the same `.env` Laravel does**, for interpolation. That is
  convenient and is why every container-only variable added to `.env.example`
  (`BRIDGE_PORT`, `DOCKER_SOCK`, `DB_QUEUE_RETRY_AFTER`) is grouped under a
  "Container only" heading — they are meaningless to `artisan serve`.
- **Livewire v4's asset route is hash-prefixed** — `/livewire-7c3e1dc9/…`, not
  `/livewire/…`. Nothing needs configuring; it is only worth knowing before
  someone curls the old path, gets a 404, and concludes the assets are missing.
- **The `vendor` stage needs `ext-intl` and `ext-zip` too**, not just the
  runtime: composer enforces the dependency tree's `ext-*` requirements at
  install time, and `filament/support` will not resolve without them. Both
  stages therefore share a `php-base` stage. The first build failed exactly
  this way.
- **`bridge.sh` and `Makefile` were restored to the repository root.** They had
  been moved under `reference/`, which Phase 8 deletes wholesale — they would
  have gone with it. Unchanged: they only ever drove `docker compose` in this
  directory.

### Verified against a real deploy, which is the phase's exit criterion

Everything below ran against the real host Docker daemon through the mounted
socket, with the app row, the database and the clone all living inside the
container:

- `docker compose up` boots clean; all three supervisord programs reach
  RUNNING; the compose healthcheck reports `healthy`.
- **Path identity proven, not assumed**: the container cloned into
  `/Users/…/the-bridge-laravel/repos/bridge-test-app` and the host sees the
  same tree at the same absolute path.
- Three successful deploys (pull, image pull, down, `up -d --build`, 5 chatty
  post-deploy steps), ~6 seconds each, `commit_sha` and `commit_message`
  recorded, and the deployed app answering 200 on the host at `:8099`.
- API fails closed with 503 before a token exists, 401 on a wrong token, 200
  with the right one — resolved through the **settings-table** layer, with
  `BRIDGE_API_TOKEN` empty.
- `X-Deploy-Done: true` / `X-Log-Offset: 824` on the log endpoint, unchanged
  from Phase 5.
- Health chain alive across the whole session: 9 `HealthCheck` rows recording
  `up`, exactly one pending job and it is on the `health` queue, zero failed
  jobs.
- Every asset the login page references returns 200 — the Vite-built Filament
  theme, all seven `filament:upgrade`-published bundles, and Livewire's JS.
- 437 tests / 1190 assertions green (fixed + seeds 1, 4242, 31337). Pint fails
  only on the pre-existing `bootstrap/providers.php`.

### What the suite cannot cover

The packaging test reads config files; it cannot run them. Nothing in CI proves
the image builds, the socket is reachable, the GID match works, or that a
deploy succeeds — those were checked by hand, above, and must be re-checked by
hand after any change to `docker/`. In particular a green suite says nothing
about an 11-second smoke deploy standing in for a 40-minute build; the
`retry_after` and `stopwaitsecs` reasoning is argued from the code, not
measured at that duration.

### Re-running the containerised check

`.env` needs `BRIDGE_PORT`, and on macOS `DOCKER_SOCK` — the Docker Desktop
socket is at `~/.docker/run/docker.sock`, **not** `/var/run/docker.sock`
(`docker context inspect --format '{{.Endpoints.docker.Host}}'`).

The fixture at `/Users/aclinton/Dev/Personal/Docker/bridge-test-app` is a local
path, so the container needs it mounted to clone it. That mount is a local
testing concern and is deliberately **not** in the committed compose file — use
an override:

```yaml
# /tmp/override.yml
services:
  bridge:
    volumes:
      - /Users/aclinton/Dev/Personal/Docker/bridge-test-app:/Users/aclinton/Dev/Personal/Docker/bridge-test-app:ro
```

```bash
docker compose -f docker-compose.yml -f /tmp/override.yml up -d --build
docker compose cp provision.php bridge:/tmp/provision.php   # see Phase 6's recipe,
docker compose exec -T bridge php artisan tinker /tmp/provision.php
```

with `health_url` set to `http://host.docker.internal:8099/` rather than
`localhost`. Then set a token
(`Setting::setValue('api_token', …)`) and `POST /api/apps/1/deploy`, or log in
at `http://127.0.0.1:8080/` and press Deploy.

Clean up with `docker compose down -t 0`, `rm -rf repos/bridge-test-app data`,
and `docker compose down` inside the fixture checkout.

### State at handoff

Phase 7 is complete. Nothing is left half-built.

| | |
|---|---|
| Branch | `laravel-port`, worktree `../the-bridge-laravel` |
| Suite | 437 tests / 1190 assertions, green (fixed + seeds 1, 4242, 31337) |
| Pint | fails only on the pre-existing `bootstrap/providers.php` |
| Phase 6 | committed as `b1fdc61` — it was still uncommitted when Phase 7 began |
| Phase 7 | committed as `3f9627a` |

Touched by this phase — 4 modified, 9 new:

```
M .env.example                              container-only vars, rename migration
M app/Jobs/PollHealthChecks.php             QUEUE const + onQueue in constructor
M tests/Unit/PollHealthChecksTest.php       the reversed queue assertion
M docs/porting-notes.md
+ docker/Dockerfile                         php-base / vendor / assets / runtime
+ docker/Caddyfile                          classic mode, :80, access log off
+ docker/entrypoint.sh                      the once-per-boot obligations
+ docker/supervisord.conf                   frankenphp + 2 single-slot workers
+ docker/php.ini
+ docker-compose.yml
+ .dockerignore
+ bridge.sh, Makefile                        restored from reference/
+ tests/Feature/Packaging/PackagingConfigTest.php
```

**Phase 7 was NOT QC'd by Opus.** Every phase from 1 to 6 was; this one went
straight from implementation to commit. If the phase gate is being honoured,
that QC is owed before or alongside Phase 8's final pass, and the obvious place
to aim it is the gap named under "What the suite cannot cover" — the packaging
test reads config files but cannot run them.

**The local environment was returned to empty** — containers down, `data/` and
`repos/bridge-test-app` removed, the fixture's own compose project torn down.
Two lines were left in `.env` on purpose (`BRIDGE_PORT=8080`,
`DOCKER_SOCK=/Users/aclinton/.docker/run/docker.sock`); `.env` is gitignored,
and re-running the containerised check needs them.

### For Phase 8 (cleanup) — the last phase

**The plan's deletion list is stale. Check before deleting; three of its items
are wrong now.**

- `src/`, `tsconfig.json` and `vitest.config.ts` are **already gone** from the
  repository root. They exist only under `reference/`, so deleting `reference/`
  discharges all three.
- **`package.json` and `node_modules/` at the root must NOT be deleted.** The
  plan says to remove them as "Node app deps", which was true of the Express
  application's; the ones there now are Vite + Tailwind v4 + laravel-vite-plugin
  and they are what builds the Filament theme. `docker/Dockerfile`'s `assets`
  stage runs `npm ci` and `npm run build` against them. Deleting them breaks the
  image build. The Express `package.json` is under `reference/`.
- Likewise "the Node Dockerfile" means `reference/docker/Dockerfile`. The root
  `docker/Dockerfile` is the live one.
- The reference's `docker/`, `bridge.sh` and `Makefile` are now duplicated —
  live copies at the root, originals under `reference/`. Deleting `reference/`
  wholesale is correct and loses nothing.

On the two files in `public/`:

- **`public/theme.js` is genuinely dead** — 504 bytes, an Express-era
  `localStorage` theme toggle, referenced by nothing outside `reference/`
  (Filament has its own dark mode). Safe to delete.
- **`public/lcars.css` is also now unreferenced by live code**, which is a
  change from when Phase 0 wrote the note below, and is a decision rather than a
  cleanup. `.lcars-log` was *copied* into
  `resources/css/filament/admin/theme.css`, so nothing loads `lcars.css` any
  more — but it is still the original design system, the source the four brand
  colours were mapped from, and the thing the theme's hardcoded hex values are
  supposed to track (see "LCARS" under Project gotchas). Deleting it makes that
  provenance unrecoverable. Recommend keeping it; do not delete it silently
  either way.

Verification items that need care:

- **"No SSE survives" (plan verification #6) will produce false hits.** Grepping
  for `EventSource` / `text/event-stream` / `Last-Event-ID` matches two things
  that are not live code: the historical explanation in
  `app/Livewire/DeploymentLog.php`'s docblock (deliberate — it records what
  replaced what), and `docs/superpowers/plans/*.md`, which are May-2026 planning
  artifacts predating this port. Scope the grep to `app/`, `resources/`,
  `routes/`, `public/` and `config/`, and read the two docblock hits rather than
  deleting them.
- **The `bridge-mcp` consumer check (#5) should be re-run against the
  container**, not `php -S`. Phase 5 ran it against a bare PHP server because no
  container existed; one exists now, and the container is what ships. The
  `php artisan serve` trap Phase 5 documented does not apply to it.
- **`retry_after` is discharged, not inherited.** Phase 6 left it open; the
  two-queue split resolved it. What Phase 8 must not do is add a worker to
  `default` without revisiting it.

Still owed:

- The README rewrite should carry the `REPOS_PATH` → `BRIDGE_REPOS_PATH` and
  `SESSION_SECRET` → `APP_KEY` migrations, the `DOCKER_SOCK` macOS caveat, and
  the `health_url`-must-resolve-from-inside-the-container point. All three live
  only in `.env.example` and here. **Done in Phase 8** — all four are in the
  README, the last one under its own heading.
- The `RollbackAction` docblock decision from Phase 6 is still open; Phase 7 did
  not touch it. **Done in Phase 8** — the guard was deleted, not the docblock
  patched.

## Phase 8 — cleanup and final QC

The last phase. Files: `reference/` and `public/theme.js` deleted, `README.md`
rewritten, three application changes (`RollbackAction`, `PollHealthChecks`
docblock, `AdminPanelProvider`), two `docker/` changes, and four test files —
two new, two extended. 477 tests / 1386 assertions green; Pint clean, including
the `bootstrap/providers.php` failure that had been carried since Phase 0.

### The deletion list, as executed

Phase 7's corrections held. What actually happened:

- `reference/` deleted wholesale (492K, 62 files). `src/`, `tsconfig.json`,
  `vitest.config.ts` and the Express `package.json`/Dockerfile went with it —
  they existed nowhere else, which is exactly what the stale plan item claimed
  otherwise.
- **Root `package.json` and `node_modules/` kept.** They are Vite + Tailwind v4
  and they build the Filament theme. Proven rather than argued: the image was
  rebuilt after the deletions and its `assets` stage still runs `npm ci && npm
  run build`.
- `public/theme.js` deleted — an Express-era `localStorage` toggle, referenced
  by nothing.
- `my-app/`, an untracked empty checkout the create-app tests leave at the
  repository root when `BRIDGE_REPOS_PATH` resolves relative to it, removed. It
  stays in `.dockerignore` because a test run recreates it.

### `public/lcars.css` is kept, and is now load-bearing again

Phase 7 left this open, correctly: the file was unreferenced but it is the
source the theme's four brand colours and `.lcars-log` were copied from, and
deleting it makes that provenance unrecoverable.

Rather than keep it on the strength of a comment, `tests/Feature/LcarsThemeTest`
now compares the copies against it: the theme's hardcoded `#0A0A0A`/`#33FF33`
against lcars.css's `--color-log-bg`/`--color-log-text`, and the panel's four
`Color::hex()` values against `--color-brand`/`--color-command`/
`--color-sciences`/`--color-ops`. It also fails if either log variable is
declared more than once — the exact drift the "LCARS" gotcha warns about, since
a `[data-theme="dark"]` override is something the hardcoded copies cannot
follow. Deleting `lcars.css` now fails three tests instead of quietly stranding
the design system.

### `RollbackAction`: the guard was deleted, not the docblock corrected

Open since Phase 6. The in-action `! $record->commit_sha` refusal was proven
unreachable the same way Phase 6 proved it for `ResetDeploymentAction` — write
the test that asserts the danger notification, watch it fail with "A
notification was not sent" — and then deleted. `visible()` is re-evaluated
against a freshly-loaded record at `callMountedAction()`, so a stale page finds
the action hidden, therefore disabled, and it unmounts without running.

Kept in its place: `test_rolling_back_a_deployment_whose_sha_was_cleared_since_
the_page_rendered_does_nothing`, which pins the mechanism that actually does the
work. Deleting `visible()` kills it and two others. Phase 5's
`POST /apps/:id/rollback` still keeps its own 400 — it has no Filament in front
of it.

### Phase 7's QC, owed and now paid — two real defects

Aimed where the handoff said to aim it: "the packaging test reads config files;
it cannot run them." Both findings were things a green suite was actively
hiding.

**1. The container could not boot on an empty `/data`.** `optimize:clear` runs
`cache:clear` as one of its five steps; `CACHE_STORE` is `database`, so on a
first boot that is a `DELETE` against a table `migrate` has not created yet.
The command throws, `set -e` stops the entrypoint, and the container
restart-loops with `no such table: cache` having never migrated. Phase 7's own
verification missed it because it ran against a `/data` that already had a
database. The fix clears `config`/`route`/`view`/`event` individually before
`migrate` — the config cache still must go first, or migrations read the
previous boot's `DB_DATABASE` — and runs `cache:clear` after it, once the table
exists.

**2. Every restart added a health chain.** Each tick re-dispatches its successor
with a 60-second delay, and that successor is a row in the database queue, which
lives on `/data` and outlives the container. So the entrypoint's "exactly once
per container start" kickoff was adding a chain rather than starting one:
reproduced at one stop/start → two pending health jobs, and one more on every
restart after that. Doubling request volume and `HealthCheck` rows, with nothing
anywhere reporting it. The entrypoint now runs `queue:clear --queue=health`
immediately before the kickoff — the `health` queue carries nothing but this
chain, so clearing it is precisely "cancel the old chain", and `default` (where
deploys live) must never be cleared. Verified in the real container: one pending
health job before a restart, one after.

A third case was found and deliberately **not** fixed: if the health worker is
SIGKILLed mid-tick, the `finally` never runs and the reserved job is failed by
`--tries=1` when `retry_after` makes it visible again, so that chain is gone
until the next boot re-kicks it. A `failed()` hook that re-dispatched would fork
the chain in two on every ordinary throw, which is what `--tries=1` exists to
prevent. A missing chain is visible (health checks stop); a forked one doubles
silently forever. Both are recorded in `PollHealthChecks`'s docblock.

### What the packaging test can now actually run

The gap was that every assertion read the files as text, and text is happy with
a YAML file that no longer parses or an entrypoint with an unclosed quote. Now:

- `docker-compose.yml` is parsed with `symfony/yaml` and asserted against the
  tree, not the source — including `APP_DEBUG`, `env_file`, both volume
  mappings, and the socket's container-side path.
- `supervisord.conf` is parsed as ini. A duplicated `[program:]` header reads
  fine as text and makes supervisord refuse to start; it does not survive this.
- `docker/entrypoint.sh` gets `sh -n`. The one thing about the entrypoint the
  suite can genuinely execute.
- `stop_grace_period` is compared against `deploy-worker`'s `stopwaitsecs`.
  **This pair was completely unpinned**: `stopwaitsecs=600` only matters if the
  container survives that long, and `docker stop`'s default grace period is 10
  seconds. Lowering or deleting `stop_grace_period` silently restores the
  abandoned-deploy defect while every other assertion here still passes.
- `.dockerignore` must still exclude `.env`. The vendor stage does `COPY . .`,
  so dropping that line bakes a developer `.env` into the layer; Laravel's
  Dotenv would not override compose's `APP_ENV`/`APP_DEBUG`, but it *would*
  supply everything compose omits — including `BRIDGE_API_TOKEN`, the
  difference between an API that fails closed and one that accepts a token the
  operator never set.
- `ENTRYPOINT` is pinned. Every boot obligation lives in that script.

Six mutations were run against the new assertions and all six were killed.

Two stale references were also corrected: `PollHealthChecks` and
`supervisord.conf` both pointed at `tests/Feature/Packaging/SupervisordConfigTest.php`,
which has never existed — the file is `PackagingConfigTest.php`.

### Route parity, pinned rather than asserted by hand

Plan verification #3 had no test at all. `tests/Feature/RouteParityTest` now
inspects the route TABLE — that is where the documented failure lives, since a
colliding declaration in `web.php` replaces the panel's route and erases its
name with no error anywhere. It pins each surviving reference endpoint to its
handler, the webhook to `WebhookController` with no CSRF middleware, the three
`filament.admin.*` route names, which routes are token-guarded and which two
deliberately are not, that no route URI contains `stream`, and the seven
reference endpoints that became Livewire interactions and must NOT resolve as
HTTP routes. Two mutations — a colliding `Route::get('/login')` and dropping
`api.token` from `routes/parity.php` — were both killed.

`PanelSmokeTest` gained the three page GETs the reference asserted and nothing
here covered: `/apps/create`, `/apps/{id}`, `/apps/{id}/edit`. Every other panel
test mounts these pages through `Livewire::test()`, which bypasses routing
entirely.

### Verification items, as run

1. **Per-phase QC** — Phase 7's was owed and is above.
2. **Parity checklist** — all 115 cases mapped; the table now lives in this file
   (below) so it survived `reference/`'s deletion. 112 map directly, 2 SSE cases
   were discharged in Phase 6, 1 (`consecutiveFailures`) is the deliberate drop
   the plan itself called for.
3. **Route parity** — above.
4. **Real deploy** — re-run in Phase 8 rather than inherited: the image was
   rebuilt after every deletion, booted on an empty `/data`, and deployed the
   fixture twice through the API. Path identity, the socket, the health chain
   and graceful restart all still hold.
5. **Consumer check** — re-run against the **container**, not `php -S`. All five
   `bridge_mcp.client` calls pass against `http://127.0.0.1:8080/api` with the
   token resolved from the settings table and `BRIDGE_API_TOKEN` empty:
   `list_apps`, `list_branches`, `deploy_app` (202 → a real deploy that
   succeeded, commit recorded), `get_deployment`, `get_deployment_log`. The
   incremental tail arrived in 4 chunks over the deploy; polling from the final
   offset returned an empty body with the offset unchanged; 404 and 401 both
   surfaced as `BridgeApiError`.
6. **No SSE survives** — grep scoped to `app/`, `resources/`, `routes/`,
   `public/`, `config/`, `bootstrap/`, `database/`. One hit, in
   `DeploymentLog`'s docblock, recording what replaced what. Read and kept, as
   Phase 7 advised. `RouteParityTest` pins the absence structurally.

### Smaller things

- **`public/favicon.ico` was a 0-byte file** — on the plan's "fix explicitly
  during the port" list and never done. Rebuilt at 16/32/48px from
  `public/the-bridge-logo.png` and declared on the panel with `->favicon()`, so
  the page emits a link tag rather than relying on the browser's implicit
  request.
- **`.env.example`'s `APP_URL` still said port 3000**, the Express default.
  Now `8080`, matching `BRIDGE_PORT`.
- **`bootstrap/providers.php`** — the Pint failure carried since Phase 0 is
  fixed. Pint is now clean across the whole tree.
- **Roughly 80 files cite `reference/...` paths in docblocks.** Left alone
  deliberately: they are the provenance of nearly every non-obvious decision
  here, and the Express original is still on `main` (`git show
  main:src/routes/apps.ts`). "Resuming work" at the top of this file records how
  to read one.

### State at handoff

Phase 8 is complete, and so is the port.

| | |
|---|---|
| Branch | `laravel-port`, worktree `../the-bridge-laravel` |
| Suite | 477 tests / 1386 assertions, green (fixed + seeds 1, 4242, 31337) |
| Pint | clean |
| Phase 7 | committed as `3f9627a` |

Touched by this phase — 9 modified, 2 new, 2 deleted (plus `reference/`):

```
M .dockerignore                             reference/ gone, my-app explained
M .env.example                              APP_URL port
M README.md                                 rewritten for the Laravel app
M app/Filament/Actions/RollbackAction.php   dead guard deleted, docblock corrected
M app/Jobs/PollHealthChecks.php             two chain-lifetime facts, stale test name
M app/Providers/Filament/AdminPanelProvider.php   ->favicon()
M bootstrap/providers.php                   the Phase 0 Pint failure
M docker/entrypoint.sh                      first-boot cache fix, health-chain clear
M docker/supervisord.conf                   stale test name
M public/favicon.ico                        0 bytes → a real icon
+ tests/Feature/RouteParityTest.php
+ tests/Feature/LcarsThemeTest.php
E tests/Feature/Packaging/PackagingConfigTest.php  parsed configs, sh -n, new pairs
E tests/Feature/Filament/PanelSmokeTest.php        the three page GETs
E tests/Feature/Filament/DeploymentResourceTest.php  the stale-rollback test
E tests/Feature/PollHealthCommandTest.php         queue:clear behaviour
- public/theme.js
- reference/                                 62 files
```

**The local environment was returned to empty** — containers down, `data/` and
`repos/bridge-test-app` removed, the fixture's own compose project torn down.
`.env` keeps `BRIDGE_PORT=8080` and `DOCKER_SOCK=…/.docker/run/docker.sock`;
it is gitignored and re-running the containerised check needs them.

### If there is a Phase 9

There is no planned work left. The three things most worth knowing before
opening this again:

- **Nothing in CI proves the image.** The packaging tests read and parse
  `docker/`; only `sh -n` executes anything. The build, the socket, the GID
  match and a real deploy are hand-checked — recipe under Phase 7's "Re-running
  the containerised check", which is still current apart from the fixture's
  `health_url` needing `host.docker.internal`.
- **A Dusk suite is the one real coverage gap.** Phase 6's list of what PHPUnit
  cannot see (the `wire:ignore` log box, `textContent` vs `innerHTML`,
  auto-scroll) is unchanged.
- **Deploy concurrency must stay at 1** while `retry_after` is 90. That
  invariant now has three separate warnings pointing at it and no enforcement
  beyond a test that counts workers.

## Parity acceptance

All **115** reference cases and where each landed. `reference/` was deleted in
Phase 8, so this table is the record: the originals are readable with
`git show main:tests/Feature/apiDeploy.test.ts` and friends.

| Reference file | Cases | Ported to |
|---|---|---|
| `tests/Feature/apiDeploy.test.ts` | 9 | `Feature/Api/ApiDeployTest` (auth ladder, 202 + dispatch, 404s, apps index, deployment JSON, log slice + header trio) |
| `tests/Feature/apiOpenapi.test.ts` | 4 | `Feature/Api/ApiOpenApiTest` |
| `tests/Feature/appCrud.test.ts` | 12 | `Filament/PanelSmokeTest` (the four page GETs), `Filament/CreateAppTest` (create, validation, skip_clone, `.env` seeding), `Filament/EditAppTest` (update), `Filament/AppActionsTest` (delete) |
| `tests/Feature/containerStatus.test.ts` | 2 | `Feature/ParityRoutesTest::test_containers_*` |
| `tests/Feature/deployTrigger.test.ts` | 1 | `Filament/AppActionsTest::test_the_deploy_action_creates_a_pending_deployment_dispatches_it_and_redirects_to_it` |
| `tests/Feature/envEditor.test.ts` | 2 | `Filament/AppActionsTest::test_the_env_editor_*`, plus `ParityRoutesTest::test_env_post_*` |
| `tests/Feature/migration.test.ts` | 3 | `Feature/MigrationTest::test_{apps,deployments,jobs}_table_has_correct_columns` |
| `tests/Feature/rollback.test.ts` | 2 | `Filament/DeploymentResourceTest::test_rolling_back_creates_a_new_deployment_carrying_the_source_commit_sha`, `ParityRoutesTest::test_rollback_returns_400_when_the_source_deployment_has_no_commit_sha` |
| `tests/Feature/settings.test.ts` | 2 | `Filament/SettingsPageTest` (load, save) |
| `tests/Feature/sseStream.test.ts` | 2 | replaced by polling — see below |
| `tests/Feature/webhooks.test.ts` | 4 | `Feature/WebhooksTest` (204, 401, branch mismatch, 400 no secret) |
| `tests/Unit/deployApp.test.ts` | 8 | `Unit/DeployAppTest` (success, compose failure, `commit_sha`, `rollback_sha`, pre-flight, one auto-rollback, loop guard, no prior success) |
| `tests/Unit/enums.test.ts` | 2 | `Unit/EnumsTest` |
| `tests/Unit/gitService.test.ts` | 3 | `Unit/Services/GitServiceTest` (clone ok, clone throws, pull) |
| `tests/Unit/healthCheck.test.ts` | 3 | `Unit/HealthCheckModelTest` — 2 of 3; `consecutiveFailures` deliberately dropped (see below) |
| `tests/Unit/healthPoller.test.ts` | 3 | `Unit/Services/HealthPollerTest` (records up, records down, skips no `health_url`) |
| `tests/Unit/models.test.ts` | 12 | `Unit/AppModelTest` (7) + `Unit/DeploymentModelTest` (5) |
| `tests/Unit/slackNotifier.test.ts` | 2 | `Unit/Services/SlackNotifierTest` |
| `src/services/deploySteps.test.ts` | 24 | `Unit/Services/DeployStepsTest` — 24, name for name |
| `src/services/portBindings.test.ts` | 15 | `Unit/Services/PortBindingsTest` — 32, a strict superset |

**`consecutiveFailures` is the one case with no ported test**, and that is the
plan's own instruction: it has zero callers in the reference and the defect list
says "drop unless a use is planned". Phase 1 dropped the method, so the test has
no subject. Everything else maps.

The original wording of this section, kept because it is the contract:

`reference/tests/` and `reference/src/services/*.test.ts` hold **115 cases**.
They are the behavioural contract for this port — every one should map to a
ported test. The two SSE tests are replaced by polling tests; HTML feature tests
become authenticated Filament tests.

The two SSE cases (`reference/tests/Feature/sseStream.test.ts`) were discharged
in Phase 6. Neither transfers literally — both assert transport details of a
mechanism this port does not use:

- "returns text/event-stream content type" has no counterpart at all; there is
  no stream and no content type to assert. The behaviour underneath it — output
  reaching the page as it is written — is
  `DeploymentLogTest::test_a_poll_dispatches_only_the_bytes_appended_since_the_last_one`,
  plus the real-deploy check that it arrives incrementally rather than in one
  burst.
- "emits done event for terminal deployment" becomes
  `test_the_poll_attribute_is_dropped_once_the_deployment_finishes` and
  `test_it_never_starts_polling_a_deployment_that_is_already_finished`: the
  polling equivalent of closing the stream is ceasing to ask.

(The reference README claims 35 tests. That is stale.)
