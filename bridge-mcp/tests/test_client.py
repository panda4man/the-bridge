import httpx
import pytest
import respx

from bridge_mcp import client

BASE = "http://bridge.test/api"


@pytest.fixture(autouse=True)
def bridge_env(monkeypatch):
    monkeypatch.setenv("BRIDGE_API_BASE_URL", BASE)
    monkeypatch.setenv("BRIDGE_API_TOKEN", "secret-token")


def test_base_url_required(monkeypatch):
    monkeypatch.delenv("BRIDGE_API_BASE_URL", raising=False)
    with pytest.raises(client.BridgeApiError):
        client.list_apps()


@respx.mock
def test_list_branches_happy_path():
    respx.get(f"{BASE}/branches", params={"repo_url": "https://x.git"}).mock(
        return_value=httpx.Response(200, json={"branches": ["main", "dev"]})
    )
    result = client.list_branches("https://x.git")
    assert result == {"branches": ["main", "dev"]}


@respx.mock
def test_list_branches_never_raises_on_embedded_error():
    respx.get(f"{BASE}/branches", params={"repo_url": "https://bad.git"}).mock(
        return_value=httpx.Response(200, json={"branches": [], "error": "Failed to fetch branches"})
    )
    result = client.list_branches("https://bad.git")
    assert result == {"branches": [], "error": "Failed to fetch branches"}


@respx.mock
def test_list_apps_transport_error_is_wrapped():
    respx.get(f"{BASE}/apps").mock(side_effect=httpx.ConnectError("Connection refused"))
    with pytest.raises(client.BridgeApiError) as exc_info:
        client.list_apps()
    assert exc_info.value.status_code == 0
    assert not isinstance(exc_info.value, client.BridgeApiConfigError)


@respx.mock
def test_list_apps_happy_path():
    apps = [{"id": 1, "name": "widgets", "branch": "main", "status": "idle", "repo_url": "https://x.git"}]
    respx.get(f"{BASE}/apps").mock(return_value=httpx.Response(200, json=apps))
    assert client.list_apps() == apps


@respx.mock
def test_list_apps_401_raises_bridge_api_error():
    respx.get(f"{BASE}/apps").mock(return_value=httpx.Response(401, json={"error": "Unauthorized"}))
    with pytest.raises(client.BridgeApiError) as exc_info:
        client.list_apps()
    assert exc_info.value.status_code == 401
    assert not isinstance(exc_info.value, client.BridgeApiConfigError)


@respx.mock
def test_list_apps_503_raises_config_error_distinct_from_401():
    respx.get(f"{BASE}/apps").mock(return_value=httpx.Response(503, json={"error": "API token not configured"}))
    with pytest.raises(client.BridgeApiConfigError) as exc_info:
        client.list_apps()
    assert exc_info.value.status_code == 503


@respx.mock
def test_deploy_app_happy_path():
    respx.post(f"{BASE}/apps/3/deploy").mock(
        return_value=httpx.Response(202, json={"deployment_id": 42, "app_id": 3, "status": "pending"})
    )
    result = client.deploy_app(3)
    assert result == {"deployment_id": 42, "app_id": 3, "status": "pending"}


@respx.mock
def test_deploy_app_404_raises_with_message():
    respx.post(f"{BASE}/apps/999/deploy").mock(return_value=httpx.Response(404, json={"error": "Not found"}))
    with pytest.raises(client.BridgeApiError) as exc_info:
        client.deploy_app(999)
    assert exc_info.value.status_code == 404
    assert "Not found" in exc_info.value.message


@respx.mock
def test_get_deployment_happy_path():
    dep = {
        "id": 42, "app_id": 3, "app_name": "widgets", "status": "running",
        "commit_sha": "abc123", "commit_message": "fix", "started_at": "2026-01-01T00:00:00Z",
        "finished_at": None, "log_length": 100,
    }
    respx.get(f"{BASE}/deployments/42").mock(return_value=httpx.Response(200, json=dep))
    assert client.get_deployment(42) == dep


@respx.mock
def test_get_deployment_404():
    respx.get(f"{BASE}/deployments/999").mock(return_value=httpx.Response(404, json={"error": "Not found"}))
    with pytest.raises(client.BridgeApiError) as exc_info:
        client.get_deployment(999)
    assert exc_info.value.status_code == 404


@respx.mock
def test_get_deployment_log_happy_path_folds_headers_into_result():
    respx.get(f"{BASE}/deployments/42/log", params={"offset": 10}).mock(
        return_value=httpx.Response(
            200,
            text="chunk of log",
            headers={
                "X-Log-Offset": "22",
                "X-Deploy-Status": "running",
                "X-Deploy-Done": "false",
            },
        )
    )
    result = client.get_deployment_log(42, offset=10)
    assert result == {
        "text": "chunk of log",
        "log_offset": 22,
        "deploy_status": "running",
        "deploy_done": False,
    }


@respx.mock
def test_get_deployment_log_deploy_done_true():
    respx.get(f"{BASE}/deployments/42/log", params={"offset": 0}).mock(
        return_value=httpx.Response(
            200,
            text="all done",
            headers={"X-Log-Offset": "8", "X-Deploy-Status": "success", "X-Deploy-Done": "true"},
        )
    )
    result = client.get_deployment_log(42)
    assert result["deploy_done"] is True


@respx.mock
def test_get_deployment_log_401():
    respx.get(f"{BASE}/deployments/42/log", params={"offset": 0}).mock(
        return_value=httpx.Response(401, json={"error": "Unauthorized"})
    )
    with pytest.raises(client.BridgeApiError) as exc_info:
        client.get_deployment_log(42)
    assert exc_info.value.status_code == 401
