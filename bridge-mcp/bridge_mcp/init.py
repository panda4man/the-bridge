"""Setup CLI: wire the bridge-api-guide server into a repo.

Two idempotent steps:
1. Register the stdio server in the repo's ``.mcp.json``.
2. Install agent deploy instructions as a replaceable fenced block in a target
   markdown file (``AGENTS.md`` by default).

Re-running never duplicates: an existing entry/block is left in place (or
replaced with ``--force``). Use ``--dry-run`` to preview.
"""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

SERVER_NAME = "bridge-api-guide"
BLOCK_BEGIN = "<!-- BEGIN bridge-api-guide -->"
BLOCK_END = "<!-- END bridge-api-guide -->"

GIT_SOURCE = "git+https://github.com/panda4man/the-bridge.git#subdirectory=bridge-mcp"

_TEMPLATE_PATH = Path(__file__).with_name("AGENT_INSTRUCTIONS.md")


def mcp_entry() -> dict:
    """The stdio server entry to add to .mcp.json.

    Launches via ``uvx --from <git source> bridge-mcp``. uv fetches and caches
    the package on first run — no prior ``uv tool install``, no local
    checkout, no PATH setup. Same entry works for any consumer on any
    machine. Pin a ref for reproducibility with ``GIT_SOURCE + "@<tag-or-sha>"``
    before the ``#subdirectory`` fragment if the default branch moving out
    from under a committed config is a concern.
    """
    return {"command": "uvx", "args": ["--from", GIT_SOURCE, "bridge-mcp"]}


def find_repo_root(start: Path) -> Path:
    """Walk upward from ``start`` to the nearest dir containing .git or .mcp.json.

    Falls back to ``start`` if none is found.
    """
    start = start.resolve()
    for candidate in (start, *start.parents):
        if (candidate / ".git").exists() or (candidate / ".mcp.json").exists():
            return candidate
    return start


def instructions_block() -> str:
    """The fenced, replaceable instruction block (markers included)."""
    body = _TEMPLATE_PATH.read_text(encoding="utf-8").strip()
    return f"{BLOCK_BEGIN}\n{body}\n{BLOCK_END}"


def register_mcp_json(repo_root: Path, *, force: bool = False, dry_run: bool = False) -> str:
    """Add the server to ``<repo_root>/.mcp.json``. Returns a status message."""
    path = repo_root / ".mcp.json"
    if path.exists():
        data = json.loads(path.read_text(encoding="utf-8"))
    else:
        data = {"mcpServers": {}}

    servers = data.setdefault("mcpServers", {})
    exists = SERVER_NAME in servers

    if exists and not force:
        return f"skip: '{SERVER_NAME}' already in {path.name} (use --force to overwrite)"

    servers[SERVER_NAME] = mcp_entry()
    verb, past = ("overwrite", "overwrote") if exists else ("add", "added")

    if dry_run:
        return f"dry-run: would {verb} '{SERVER_NAME}' in {path.name}"

    if path.exists():
        backup = path.with_suffix(path.suffix + ".bak")
        backup.write_text(path.read_text(encoding="utf-8"), encoding="utf-8")
    path.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")
    return f"ok: {past} '{SERVER_NAME}' in {path.name}"


def install_instructions(repo_root: Path, *, target: str = "AGENTS.md", dry_run: bool = False) -> str:
    """Install/replace the instruction block in ``<repo_root>/<target>``."""
    path = repo_root / target
    block = instructions_block()
    existing = path.read_text(encoding="utf-8") if path.exists() else ""

    pattern = re.compile(re.escape(BLOCK_BEGIN) + r".*?" + re.escape(BLOCK_END), re.DOTALL)
    if pattern.search(existing):
        new_text = pattern.sub(block, existing)
        action = "replace block in"
    elif existing.strip():
        new_text = existing.rstrip() + "\n\n" + block + "\n"
        action = "append block to"
    else:
        new_text = block + "\n"
        action = "create"

    if dry_run:
        return f"dry-run: would {action} {target}"
    if new_text == existing:
        return f"unchanged: {target} already current"

    path.write_text(new_text, encoding="utf-8")
    return f"ok: {action} {target}"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="bridge-mcp-init",
        description="Register the bridge-api-guide MCP server and install agent instructions.",
    )
    parser.add_argument("--repo", type=Path, default=None,
                        help="Repo root (default: auto-detect from current directory).")
    parser.add_argument("--target", default="AGENTS.md",
                        help="Markdown file to install instructions into (default: AGENTS.md).")
    parser.add_argument("--force", action="store_true",
                        help="Overwrite an existing .mcp.json entry.")
    parser.add_argument("--dry-run", action="store_true",
                        help="Show what would change without writing.")
    scope = parser.add_mutually_exclusive_group()
    scope.add_argument("--instructions-only", action="store_true",
                       help="Only install the AGENTS.md block; skip .mcp.json "
                            "(use when the server is registered globally with "
                            "--scope user).")
    scope.add_argument("--mcp-only", action="store_true",
                       help="Only register .mcp.json; skip the AGENTS.md block.")
    args = parser.parse_args(argv)

    repo_root = args.repo.resolve() if args.repo else find_repo_root(Path.cwd())

    print(f"Repo root: {repo_root}")
    if not args.instructions_only:
        print(register_mcp_json(repo_root, force=args.force, dry_run=args.dry_run))
    if not args.mcp_only:
        print(install_instructions(repo_root, target=args.target, dry_run=args.dry_run))
    if not args.dry_run and not args.instructions_only:
        print("Next: reload your MCP config (restart Claude Code / your client) "
              "to pick up the server.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
