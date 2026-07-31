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

## Parity acceptance

`reference/tests/` and `reference/src/services/*.test.ts` hold **115 cases**.
They are the behavioural contract for this port — every one should map to a
ported test. The two SSE tests are replaced by polling tests; HTML feature tests
become authenticated Filament tests.

(The reference README claims 35 tests. That is stale.)
