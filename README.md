# the-bridge

Docker deployment manager. Register Git repos, deploy them from an admin panel
or over an HTTP API, and watch the build log as it is written.

A deploy is: `git pull` (or `git checkout <sha>` for a rollback), `docker
compose pull`, `docker compose down`, `docker compose up -d --build`, then any
post-deploy steps the app declares. A failed post-deploy step rolls back to the
last successful commit automatically, and Slack is notified either way.

## Stack

- **Laravel 13** / PHP 8.4, with **Filament 5** as the admin panel
- **SQLite** — one file, no external database
- **Laravel queue** (`database` connection) for deploys; the panel never blocks
  on one
- **FrankenPHP** (Caddy + PHP in a single process) under **supervisord**, which
  also runs the two queue workers
- Livewire polling for the live deploy log

## Running it

The container is the supported way to run this. It talks to the **host** Docker
daemon over the bind-mounted socket — there is no Docker-in-Docker.

```bash
cp .env.example .env
$EDITOR .env          # at minimum: BRIDGE_REPOS_PATH, BRIDGE_ADMIN_EMAIL,
                      # BRIDGE_ADMIN_PASSWORD, and DOCKER_SOCK on macOS
docker compose up -d --build
```

Then open `http://localhost:8080/` (or whatever `BRIDGE_PORT` you set) and log
in with the admin credentials from `.env`. `./bridge.sh` and the `Makefile` wrap
the same compose commands.

### Configuration

`.env` is read twice: by Laravel, and by Docker Compose for the interpolation in
`docker-compose.yml`. Only the variables `docker-compose.yml` lists under
`environment:` reach the container — `env_file:` is deliberately not used,
because a development `.env` carries `APP_DEBUG=true`, and a debug-mode panel
that can deploy arbitrary code renders its own configuration to anyone who
reaches a 500.

| Variable | Default | Description |
|---|---|---|
| `BRIDGE_REPOS_PATH` | — | **Required.** Base directory apps are cloned into. Bind-mounted at the *same absolute path* inside the container. |
| `BRIDGE_PORT` | `8080` | Host port the panel is published on. |
| `DOCKER_SOCK` | `/var/run/docker.sock` | **Host** path of the Docker socket. Not this value on macOS — see below. |
| `BRIDGE_ADMIN_EMAIL` / `BRIDGE_ADMIN_PASSWORD` | — | The first panel user, created once on boot. There is no public registration and no default password: leave these unset and you reach a login screen with no account. |
| `BRIDGE_API_TOKEN` | — | Bearer token for `/api`. May instead be set from the panel's Settings screen. |
| `BRIDGE_SSH_KEY_PATH` | `/data/ssh/id_rsa` | Deploy key for private repositories. |
| `APP_KEY` | generated | Left empty, the entrypoint generates one on first boot and persists it to `/data/app_key`. No key is baked into the image. |
| `APP_URL` | `http://localhost:8080` | Externally reachable URL. Used in Slack messages and in the webhook URL the panel displays, so `localhost` is wrong anywhere but your laptop. |
| `DB_QUEUE_RETRY_AFTER` | `90` | Read together with `docker/supervisord.conf`. Safe only while exactly one worker serves the `default` queue. |

Three of these are worth expanding on, because each has a failure mode that
looks like something else entirely.

**`BRIDGE_REPOS_PATH` is mounted at the same absolute path on both sides.**
`apps.path` stores absolute paths, and they are resolved both by the worker
inside the container and by anything you run on the host. A conventional
`./repos:/repos` mapping breaks every stored path the moment the two differ.

**`DOCKER_SOCK` is not `/var/run/docker.sock` on macOS.** Docker Desktop puts it
at `~/.docker/run/docker.sock`; the default binds a path that does not exist,
which presents as every Docker call failing on a host that looks perfectly
healthy. Check with:

```bash
docker context inspect --format '{{.Endpoints.docker.Host}}'
```

**An app's health-check URL must resolve from INSIDE the container.** The health
poller runs in a queue worker, so it fetches `health_url` from the Bridge
container's network namespace — not yours. `http://localhost:8099/`, which is
what you read off your own browser, points it at the Bridge container itself.
Use `http://host.docker.internal:8099/` (on Linux the managed app needs
`extra_hosts: host.docker.internal:host-gateway`), the app's own container name
on a shared network, or a real hostname.

### Upgrading from the Express version

| Was | Now | Note |
|---|---|---|
| `REPOS_PATH` | `BRIDGE_REPOS_PATH` | The container **refuses to boot** if the old name is set and the new one is not, rather than silently cloning into `/repos`. |
| `SESSION_SECRET` | `APP_KEY` | Generated and persisted for you if unset. Changing it logs everyone out; nothing else depends on it. |
| `DB_PATH` | `DB_DATABASE` | Still SQLite, still one file on the `/data` volume. |
| `PORT` | `BRIDGE_PORT` | The container listens on `80` internally now. |

This is a rebuild, not an in-place migration: point it at a fresh `/data` and
re-register the apps.

### The `/data` volume

Holds the SQLite database, sessions, the cache, the queue, the generated
`APP_KEY`, and `data/ssh/id_rsa` — the private deploy key for every managed
private repository. It is gitignored. **Back it up.**

```bash
# Private repositories: put the deploy key in place before the first deploy
docker compose cp ~/.ssh/id_rsa bridge:/data/ssh/id_rsa
```

Everything else in the container is disposable and rebuilt on boot.

## The API

All `/api` routes take `Authorization: Bearer <token>`, resolved from
`BRIDGE_API_TOKEN` first and the `api_token` settings row second. **Auth fails
closed**: with neither set, every call gets `503 API token not configured`. An
empty token never means a public API.

```
GET  /api/apps                      list apps
POST /api/apps/{id}/deploy          queue a deploy → 202
GET  /api/deployments/{id}          status, including log_length
GET  /api/deployments/{id}/log      log slice from ?offset, with
                                    X-Log-Offset / X-Deploy-Status / X-Deploy-Done
GET  /api/branches?repo_url=...     remote branches
GET  /api/openapi.json, /api/docs   the spec, and Swagger UI (both unauthenticated)
```

`POST /apps/{id}/webhook` is the GitHub webhook: public, HMAC-signed with the
app's own secret, and outside the CSRF-protected `web` group. It returns `204`
on a queued deploy and `200 {skipped}` when the pushed branch is not the one the
app tracks.

Agents deploying through this API should use the
[`bridge-mcp`](bridge-mcp/README.md) MCP server rather than calling it directly.

## Local development

```bash
composer install
npm install && npm run build     # builds the Filament theme (Tailwind v4, CSS-first)
php artisan migrate
php artisan db:seed              # creates the admin from BRIDGE_ADMIN_* in .env
```

Two processes, because deploys run on the queue:

```bash
php artisan serve --port=8123    # http://127.0.0.1:8123/
php artisan queue:work           # deploys and health checks
```

Point `BRIDGE_REPOS_PATH` at a directory inside the project — `/repos` cannot be
created on macOS, where the root volume is read-only under SIP — and give it an
absolute path, since a relative one resolves against each process's working
directory and those differ.

One trap worth knowing: `php artisan serve` forwards only a fixed allowlist of
environment variables to its child process, so `DB_DATABASE=… php artisan serve`
silently reads your normal database instead. Use
`php -S 127.0.0.1:8765 -t public public/index.php` with the environment inline
when that matters.

## Tests

```bash
php artisan test                 # the whole suite
php artisan test --filter=Packaging
vendor/bin/pint --test           # formatting
```

Every one of the Express original's 115 test cases maps to a test here; the
mapping is recorded in `docs/porting-notes.md` under "Parity acceptance". What
the suite deliberately does **not** cover is the container itself:
`tests/Feature/Packaging` reads `docker/` as configuration, but nothing in the
suite builds the image, reaches the Docker socket, or runs a real deploy.
Re-check those by hand after changing anything under `docker/` — the recipe is
in `docs/porting-notes.md`, "Re-running the containerised check".

## Documentation

- `docs/porting-notes.md` — how this was built and, more usefully, why each
  non-obvious decision went the way it did. Read it before changing anything
  that looks surprising.
- `AGENTS.md` — code navigation policy for AI agents.
- The Express original this replaced is still on the `main` branch
  (`git show main:src/routes/apps.ts`); docblocks throughout this codebase cite
  it by path as the behavioural specification.

## For AI agents

Use **Sonnet** (`claude-sonnet-4-6`) for implementation — editing files, writing
code, running tests. Use **Opus** (`claude-opus-4-8`) for planning —
architecture decisions, task decomposition, design review.
