from bridge_mcp import content

REQUIRED_KEYS = {"title", "method", "path", "auth", "params", "response", "curl_example", "notes"}
EXPECTED_SLUGS = {
    "list-branches", "list-apps", "deploy-app", "get-deployment", "get-deployment-log",
}


def test_all_expected_actions_present():
    assert set(content.ENDPOINTS) == EXPECTED_SLUGS


def test_every_endpoint_spec_is_complete():
    for slug, spec in content.ENDPOINTS.items():
        assert REQUIRED_KEYS <= set(spec), f"{slug} missing keys"
        assert spec["method"] in {"GET", "POST"}
        assert spec["path"].startswith("/")
        assert spec["auth"] in {"none", "bearer"}
        for value in spec.values():
            assert value, f"{slug} has empty field"


def test_render_action_includes_method_and_path():
    md = content.render_action("deploy-app")
    assert "POST" in md
    assert "/api/apps/{id}/deploy" in md
    # curl example must be fully formatted (no leftover {base} placeholder)
    assert "{base}" not in md


def test_render_action_unknown_slug_is_helpful():
    md = content.render_action("nope")
    assert "Unknown action 'nope'" in md
    for slug in content.action_slugs():
        assert slug in md


def test_render_index_lists_every_action():
    idx = content.render_index()
    for slug in content.action_slugs():
        assert slug in idx


def test_overview_mentions_auth_and_instruction_only():
    assert "Authorization: Bearer" in content.OVERVIEW
    assert "instruction-only" in content.OVERVIEW


def test_workflows_format_without_leftover_placeholders():
    a = content.WORKFLOW_DEPLOY_AND_WATCH.format(base=content.API_BASE, app_id="3")
    b = content.WORKFLOW_FIND_AND_DEPLOY_BRANCH.format(base=content.API_BASE, repo_url="x")
    c = content.WORKFLOW_CHECK_DEPLOY_STATUS.format(base=content.API_BASE, deployment_id="42")
    for text in (a, b, c):
        assert "{base}" not in text
        assert text.strip()
