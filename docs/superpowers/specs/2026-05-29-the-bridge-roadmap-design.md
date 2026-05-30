# The Bridge — Near-Term Feature Roadmap

**Date:** 2026-05-29
**Audience:** Solo developer (self-hosted)
**Inspiration:** Laravel Forge (lite)

## Context

The Bridge is a personal git/docker deploy application. Current capabilities cover app CRUD, deployment queuing with SSE log streaming, .env editing, git integration, and docker-compose support. The biggest gap is observability — no visibility into whether deployed apps are actually running and healthy. This roadmap addresses that gap first, then layers in automation, operations, and a distinctive UI.

---

## Feature 1: App Health Checks

**What:** Periodic HTTP pings to a configurable endpoint per app. Shows live up/down status throughout the UI.

**Data changes:**
- Add `health_url` (nullable string) and `health_check_interval` (int, seconds, default 60) to `apps` table
- New `health_checks` table: `id`, `app_id`, `status` (up/down/unknown), `response_time_ms`, `http_status_code`, `checked_at`

**Behavior:**
- Background worker polls each app with a `health_url` on its interval using `fetch()`
- Stores last check result; keeps last 20 results for a simple history sparkline
- Status badge shown on apps list and app detail page: green (up), red (down), gray (no URL configured)
- Failed checks increment a counter; alert state triggers after 2 consecutive failures (avoids flapping)

**UI:** Badge on app cards + detail page. Optional: small response-time graph on detail page.

---

## Feature 2: Container Status Dashboard

**What:** On-demand docker-compose container state, separate from deployment status.

**Behavior:**
- App detail page runs `docker-compose ps --format json` in the app's working directory
- Parses and displays per-container: name, state (running/exited/restarting), ports
- Loaded on page render (no polling needed — manual refresh is fine)
- "Deployment succeeded" ≠ "container is running" — this makes that distinction visible

**UI:** Collapsible "Containers" section on app detail page. Color-coded state chips using the division color system (running = Sciences Blue, exited/error = Command Red, restarting = Ops Gold).

---

## Feature 3: Auto-Deploy Webhooks

**What:** Per-app webhook endpoint that queues a deployment on valid GitHub/GitLab push events.

**Data changes:**
- Add `webhook_secret` (nullable string) to `apps` table — generated on demand, shown once

**Endpoint:** `POST /apps/:id/webhook`
- Validates HMAC-SHA256 signature (`X-Hub-Signature-256` for GitHub, `X-Gitlab-Token` for GitLab)
- Optionally filters by branch (only deploy if push is to the app's configured branch)
- On valid request: queues a deployment via the existing Bull queue (`src/jobs/deployApp.ts`)
- Returns `204` on success, `401` on invalid signature, `200` with no-op message on branch mismatch

**UI:** "Webhook" section on app edit page — shows webhook URL, secret (masked), regenerate button, branch filter toggle.

---

## Feature 4: Deployment Notifications (Slack)

**What:** Post a Slack message when a deployment reaches a terminal state (Success or Failed).

**Data changes:**
- New `settings` table (key/value): stores `slack_webhook_url` globally
- Optional: per-app `slack_channel_override`

**Behavior:**
- After deployment job resolves to `Success` or `Failed`, fire a `fetch()` POST to the Slack webhook URL
- Message includes: app name, deployment status, duration, link to deployment detail page
- Failed deployments include last 20 lines of the log in the Slack message

**UI:** Settings page (`/settings`) — global Slack webhook URL input + test button. Per-app override on app edit page.

---

## Feature 5: Rollback

**What:** Redeploy an app at a specific previous git commit SHA.

**Data changes:**
- Add `commit_sha` (nullable string) and `commit_message` (nullable string) to `deployments` table
- Populated at deploy time via `git rev-parse HEAD` after pull

**Behavior:**
- Deployment job captures commit SHA post-pull and stores it
- "Rollback to this commit" button on each deployment in the app detail history list
- Rollback queues a new deployment job with `{ rollback_sha: "<sha>" }` in the job payload
- Deploy job detects `rollback_sha`: does `git checkout <sha>` instead of `git pull`, then runs compose

**UI:** Each deployment row in app detail gets a "Rollback" button (visible on non-active deployments). Rollback creates a new `Deployment` record so it appears in history with type `rollback`.

---

## Feature 6: LCARS-Inspired UI + Light/Dark Mode

**What:** Visual redesign inspired by Star Trek TNG's LCARS interface. Division colors map to app concepts. Full light/dark mode support.

### Color System

| Token | Hex | Usage |
|-------|-----|-------|
| `--color-brand` | `#FF9900` | Primary accent, nav, interactive elements, headers |
| `--color-command` | `#CC0000` | Deployments, critical/error states |
| `--color-sciences` | `#3399FF` | App health, info states, links |
| `--color-ops` | `#FFCC00` | Warnings, pending/queued states |
| `--color-bg` | `#0D0D0D` (dark) / `#F4F4F4` (light) | Page background |
| `--color-surface` | `#1A1A1A` (dark) / `#FFFFFF` (light) | Card/panel background |
| `--color-text` | `#F0E6D0` (dark) / `#1A1A1A` (light) | Primary text |

### LCARS Design Language

- **Nav:** Horizontal bar with amber brand color, pill-shaped section labels, bold uppercase text
- **Cards/panels:** Left or top colored border strip in division color, slightly rounded corners
- **Status badges:** Pill-shaped, filled with division color (not just border)
- **Buttons:** Pill-shaped, amber primary, outlined variants for secondary actions
- **Section headers:** Bold uppercase with amber left border accent
- **Logs:** Existing mono font preserved; dark surface regardless of mode

### Light/Dark Mode

- CSS custom properties for all colors (already listed above)
- Default: `prefers-color-scheme` media query
- Override: `data-theme="light"|"dark"` on `<html>`, toggled via button in header
- Preference stored in `localStorage`
- Light mode preserves LCARS color language — same palette, adjusted contrast ratios

### Implementation Scope

- New CSS file: `public/lcars.css` (CSS custom properties + global component styles)
- Update all EJS views to use new class names and tokens
- No JS framework required — plain CSS + one small theme-toggle script

---

## Build Order

Features should be built in this order — each one is independently shippable:

1. **Container status** (lowest risk, read-only, no schema changes)
2. **App health checks** (schema + background worker)
3. **Rollback** (schema + deploy job change)
4. **Auto-deploy webhooks** (schema + new endpoint)
5. **Slack notifications** (settings schema + notify hook in worker)
6. **LCARS UI** (CSS/template work, no backend changes)

---

## Verification

Each feature can be verified independently:

| Feature | How to verify |
|---------|---------------|
| Container status | Deploy an app, visit detail page, confirm container names + states shown |
| Health checks | Set `health_url` to a running service; confirm badge turns green. Set to bad URL; confirm red after 2 checks |
| Webhooks | Send a signed `POST` with `curl`; confirm deployment queued. Send with bad signature; confirm `401` |
| Notifications | Trigger a deploy; confirm Slack message received with correct app name + status |
| Rollback | Deploy twice, click rollback on first deployment, confirm app runs at original commit SHA |
| LCARS UI | Toggle light/dark; confirm color tokens switch. Verify contrast on all status badge combinations |
