"""HTTP client for The Bridge deployment API.

Reads ``BRIDGE_API_BASE_URL`` and ``BRIDGE_API_TOKEN`` from the environment so
the MCP tools in ``server.py`` never handle credentials or URLs directly.
"""

from __future__ import annotations

import os
from typing import Any

import httpx


class BridgeApiError(Exception):
    """Raised when The Bridge API returns a non-2xx response."""

    def __init__(self, status_code: int, message: str) -> None:
        super().__init__(f"[{status_code}] {message}" if status_code else message)
        self.status_code = status_code
        self.message = message


class BridgeApiConfigError(BridgeApiError):
    """Raised when The Bridge server reports it has no API token configured (503).

    Distinct from a 401: the caller's token isn't wrong, the server has none set up.
    """


def _base_url() -> str:
    base = os.environ.get("BRIDGE_API_BASE_URL")
    if not base:
        raise BridgeApiError(0, "BRIDGE_API_BASE_URL is not set")
    return base.rstrip("/")


def _auth_headers() -> dict[str, str]:
    token = os.environ.get("BRIDGE_API_TOKEN")
    return {"Authorization": f"Bearer {token}"} if token else {}


def _error_message(response: httpx.Response) -> str:
    try:
        body = response.json()
    except ValueError:
        return response.text or response.reason_phrase
    if isinstance(body, dict) and "error" in body:
        return str(body["error"])
    return response.text or response.reason_phrase


def _raise_for_status(response: httpx.Response) -> None:
    if response.status_code == 503:
        raise BridgeApiConfigError(503, _error_message(response))
    if response.status_code >= 400:
        raise BridgeApiError(response.status_code, _error_message(response))


def _send(method: str, path: str, *, auth: bool, params: dict[str, Any] | None = None) -> httpx.Response:
    """Issue one request, normalizing transport failures (connection refused,
    timeout, DNS) into a BridgeApiError alongside the HTTP-status errors raised
    by ``_raise_for_status``."""
    headers = _auth_headers() if auth else {}
    try:
        with httpx.Client(base_url=_base_url(), headers=headers) as http:
            return http.request(method, path, params=params)
    except httpx.HTTPError as exc:
        raise BridgeApiError(0, str(exc)) from exc


def list_branches(repo_url: str) -> dict[str, Any]:
    """GET /branches. No auth. Never raises for API-level failure — the real
    endpoint always answers 200, including its own ``{"branches": [], "error":
    "..."}`` shape on failure; that dict is returned as-is."""
    response = _send("GET", "/branches", auth=False, params={"repo_url": repo_url})
    return response.json()


def list_apps() -> list[dict[str, Any]]:
    """GET /apps (bearer auth)."""
    response = _send("GET", "/apps", auth=True)
    _raise_for_status(response)
    return response.json()


def deploy_app(app_id: int) -> dict[str, Any]:
    """POST /apps/{id}/deploy (bearer auth). Fire-and-forget: the deployment is
    queued, not finished, when this returns."""
    response = _send("POST", f"/apps/{app_id}/deploy", auth=True)
    _raise_for_status(response)
    return response.json()


def get_deployment(deployment_id: int) -> dict[str, Any]:
    """GET /deployments/{id} (bearer auth)."""
    response = _send("GET", f"/deployments/{deployment_id}", auth=True)
    _raise_for_status(response)
    return response.json()


def get_deployment_log(deployment_id: int, offset: int = 0) -> dict[str, Any]:
    """GET /deployments/{id}/log?offset= (bearer auth). Stateless per call — the
    caller tracks and passes back ``offset``/``log_offset`` to tail incrementally."""
    response = _send("GET", f"/deployments/{deployment_id}/log", auth=True, params={"offset": offset})
    _raise_for_status(response)
    return {
        "text": response.text,
        "log_offset": int(response.headers.get("X-Log-Offset", 0)),
        "deploy_status": response.headers.get("X-Deploy-Status", ""),
        "deploy_done": response.headers.get("X-Deploy-Done", "false") == "true",
    }
