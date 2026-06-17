import json

from bridge_mcp import init


def test_register_mcp_json_creates_file(tmp_path):
    msg = init.register_mcp_json(tmp_path)
    assert msg.startswith("ok: add")
    data = json.loads((tmp_path / ".mcp.json").read_text())
    assert data["mcpServers"][init.SERVER_NAME] == init.mcp_entry()


def test_register_mcp_json_preserves_other_servers_and_backs_up(tmp_path):
    path = tmp_path / ".mcp.json"
    path.write_text(json.dumps({"mcpServers": {"jcodemunch": {"command": "x"}}}))
    init.register_mcp_json(tmp_path)
    data = json.loads(path.read_text())
    assert "jcodemunch" in data["mcpServers"]
    assert init.SERVER_NAME in data["mcpServers"]
    assert (tmp_path / ".mcp.json.bak").exists()


def test_register_mcp_json_is_idempotent(tmp_path):
    init.register_mcp_json(tmp_path)
    msg = init.register_mcp_json(tmp_path)
    assert msg.startswith("skip:")


def test_register_mcp_json_force_overwrites(tmp_path):
    init.register_mcp_json(tmp_path)
    msg = init.register_mcp_json(tmp_path, force=True)
    assert "overwrote" in msg


def test_register_dry_run_writes_nothing(tmp_path):
    msg = init.register_mcp_json(tmp_path, dry_run=True)
    assert msg.startswith("dry-run")
    assert not (tmp_path / ".mcp.json").exists()


def test_install_instructions_creates_and_is_idempotent(tmp_path):
    msg = init.install_instructions(tmp_path, target="AGENTS.md")
    assert msg.startswith("ok: create")
    text = (tmp_path / "AGENTS.md").read_text()
    assert init.BLOCK_BEGIN in text and init.BLOCK_END in text

    # Re-run: block replaced in place, no duplication.
    init.install_instructions(tmp_path, target="AGENTS.md")
    text2 = (tmp_path / "AGENTS.md").read_text()
    assert text2.count(init.BLOCK_BEGIN) == 1
    assert text2.count(init.BLOCK_END) == 1


def test_install_instructions_appends_to_existing_file(tmp_path):
    path = tmp_path / "AGENTS.md"
    path.write_text("# Existing\n\nKeep me.\n")
    init.install_instructions(tmp_path, target="AGENTS.md")
    text = path.read_text()
    assert "Keep me." in text
    assert text.count(init.BLOCK_BEGIN) == 1


def test_find_repo_root_detects_git_marker(tmp_path):
    (tmp_path / ".git").mkdir()
    sub = tmp_path / "a" / "b"
    sub.mkdir(parents=True)
    assert init.find_repo_root(sub) == tmp_path
