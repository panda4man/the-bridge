# the-bridge-deploy — rewrite brief

**Status: largely resolved.** `skills/the-bridge-deploy/SKILL.md` has since been
rewritten into a generic, environment-agnostic, MCP-only skill. This document is
kept as the record of *why*, and for the open items at the end. Read it as
history, not as a live task list — issues 1–5 and 7 are addressed in the current
`SKILL.md`, and issue 6's generic/consumer split is the shape it now has.

An intermediate rewrite still written for the chainbreakerspodcast consumer was
dropped rather than committed: it was superseded by `SKILL.md` and was the only
file carrying consumer-specific infrastructure detail, which does not belong in
a public repo.

The original curl-based skill this replaced lived in the `chainbreakerspodcast`
repo and has been deleted there in favour of an install sourced from this repo.

## Why this is being rewritten

On 2026-08-05 an agent deployed chainbreakerspodcast to **production** by
`curl`-ing the REST API directly, because that is what this skill documents at
every step. The operator's standing instruction is that the-bridge MCP tools are
the only sanctioned interface. The skill overrode the preference simply by being
more specific and more immediately actionable.

Treat that as the acceptance criterion: **an agent that follows this skill
literally must end up calling MCP tools, never `curl`.**

## Issues, highest impact first

### 1. Documents raw `curl` as the primary interface — must be MCP

Every operational section (trigger, log streaming, final status, listing apps)
gives a `curl` invocation against `http://the-bridge.homelab/api/...` with a
bearer token. This repo ships `bridge-mcp`, which exposes exactly those
operations as MCP tools:

| MCP tool | Signature | Source |
|---|---|---|
| `list_branches` | `(repo_url: str)` | `bridge-mcp/bridge_mcp/server.py:64` |
| `list_apps` | `()` | `bridge-mcp/bridge_mcp/server.py:71` |
| `deploy_app` | `(app_id: int)` | `bridge-mcp/bridge_mcp/server.py:77` |
| `get_deployment` | `(deployment_id: int)` | `bridge-mcp/bridge_mcp/server.py:84` |
| `get_deployment_log` | `(deployment_id: int, offset: int = 0)` | `bridge-mcp/bridge_mcp/server.py:90` |

Rewrite every step in terms of these. Add an explicit prohibition near the top —
not a preference, a rule: never call the REST API directly, including for
read-only polling or listing.

### 2. The hand-rolled header parsing is already done for you

The skill instructs the agent to read `X-Log-Offset`, `X-Deploy-Status` and
`X-Deploy-Done` response headers out of a curl `-D` dump. `get_deployment_log`
already returns them as fields (`bridge-mcp/bridge_mcp/client.py:102-112`):

```python
{
    "text": ...,          # log chunk from offset
    "log_offset": int,    # next offset to request
    "deploy_status": str, # pending|running|success|failed
    "deploy_done": bool,  # already a bool, not the string "true"
}
```

Note `deploy_done` is a real bool — the current skill's `[ "$done_flag" = "true" ]`
string comparison has no analogue and should not be carried over.

### 3. The zsh `status` gotcha should be deleted, not ported

The "zsh reserves `status` as a read-only variable" warning only exists because
the skill tells the agent to write a shell poll-loop. With MCP polling there is
no shell variable and no loop. Delete it along with the curl loop it belongs to.

### 4. `Monitor` tool guidance no longer applies

Step 2 tells the agent to stream logs with the `Monitor` tool. `Monitor` watches
a shell command's stdout; there is no shell command in an MCP flow. Replace with
guidance on polling `get_deployment_log`, advancing `offset` from `log_offset`,
and stopping when `deploy_done` is true.

### 5. Auth section becomes obsolete

`set -a; source .env; set +a`, "never print the token", `BRIDGE_API_TOKEN` — the
MCP server handles its own auth and base URL. Cut this down to a line stating
that the MCP is configured independently and the agent never handles the token.
Note the app *id* variables are a separate concern (see #6).

### 6. Skill is consumer-specific but now lives in the owner repo

These are all chainbreakerspodcast facts, not the-bridge facts:

- the "Which app" table (two environments, ports 8184, `chainbreakerspodcast.com`)
- `BRIDGE_APP_ID` / `BRIDGE_DEVELOP_APP_ID` variable names
- branch names `content` / `develop-content`, and the
  `develop` → `main` → `content` promotion rule
- `DEPLOY_ENV` being validated against literals in
  `docker/php/content-sync-entrypoint.sh`
- the claim that `.claude/settings.json` auto-approves the develop form by
  command prefix — that permission entry is written against the *curl* command
  string and will stop matching entirely once the flow is MCP calls

Decide the split. Suggested: this skill covers generic mechanics (resolve app id,
deploy, poll, interpret terminal state) and states that a consuming repo must
supply its own environment table; the consuming repo keeps a thin skill or
CLAUDE.md section holding only its own table and rules.

### 7. Content worth preserving through the rewrite

Do not lose these — they are the genuinely valuable parts, and several are not
recoverable from the API surface:

- **Environment-resolution rules 1–5**, especially "an unqualified *deploy* means
  develop" and "production requires an explicit production word in the user's own
  message". This is the safety core of the skill.
- **Auto-rollback produces a second deployment record** — a failed deploy
  enqueues a redeploy of the last good commit, so two records appear and the
  second is not another person deploying.
- **Don't deploy both environments concurrently** — shared Dockerfile build cache
  contention.
- **`deploy_done` does not imply success** — check `deploy_status` for
  `success` vs `failed`. Currently phrased in terms of the header; restate for
  the field.
- **Confirm what actually shipped** — verify the returned `commit_sha` against
  the branch you intended. Worth strengthening: the two apps share a repo URL and
  differ only by branch, so this is the main defence against deploying the wrong
  environment.

### 8. Minor

- The frontmatter `description` says "This repo has TWO environments", which is
  false in this repo. Rewrite for whatever scope the skill ends up with.
- `GET http://the-bridge.homelab/api/openapi.json` is offered as the
  machine-readable contract. Keep the pointer if useful, but make clear it is for
  human/agent reading, not an invitation to call the API.
- The "if anything here looks stale, re-resolve via
  `mcp__homelab-kb-http__query_service('the-bridge')`" instruction is good.
  Keep it.

## Verification

There is no test harness for skill text, so verify by reading:

1. Search the rewritten file for `curl` — should be zero operational hits.
2. Every step should name a concrete MCP tool.
3. The production-confirmation rule must survive intact.
4. Tool names and signatures must match `bridge-mcp/bridge_mcp/server.py`.

## Before publishing — this repo is public

`panda4man/the-bridge` is a public GitHub repo. No credentials, tokens, or UUIDs
appear in these files (scanned 2026-08-05), and `SKILL.md` is clean.

The one file that carried a LAN address and port — the superseded consumer-
specific draft — was dropped before the first commit rather than deleted in a
follow-up, since a later deletion would still leave the blob in pushed history.
The only internal reference remaining is the `the-bridge.homelab` hostname in
this file: not routable, not a secret, and meaningless outside the LAN.

Keep this in mind for future additions here — consumer environment tables carry
addresses, ports and branch names, and this repo is the wrong place for them.
That separation is what "What a consuming repo must provide" in `SKILL.md`
enforces.

## Open decisions for the operator

- **What happens to the chainbreakerspodcast copy?** It was copied, not moved —
  that repo still has its own working version. Options: delete it and rely on
  this one, or leave a slimmed consumer-side skill holding only the environment
  table. Not actioned either way.
- **How does a consuming repo get this skill?** No mechanism exists today for a
  skill in this repo to be visible to an agent working in another. Symlink,
  install step in `bridge.sh`, or plugin packaging — undecided.
- **`.claude/settings.json` permission entries** in consuming repos are written
  against curl command prefixes and will need replacing with MCP tool
  permissions once the flow changes.
