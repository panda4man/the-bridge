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

## Parity acceptance

`reference/tests/` and `reference/src/services/*.test.ts` hold **115 cases**.
They are the behavioural contract for this port — every one should map to a
ported test. The two SSE tests are replaced by polling tests; HTML feature tests
become authenticated Filament tests.

(The reference README claims 35 tests. That is stale.)
