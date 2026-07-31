#!/bin/sh
set -e

BRIDGE_DIR="$(cd "$(dirname "$0")" && pwd)"
DOCKGE_CONTAINER="dockge"

# git ignores safe.directory unless it comes from global/system config, so
# ensure the exception exists in the global config (idempotent) before any pull.
git_pull() {
    git config --global --get-all safe.directory 2>/dev/null | grep -qxF "$BRIDGE_DIR" \
        || git config --global --add safe.directory "$BRIDGE_DIR"
    git -C "$BRIDGE_DIR" pull
}

case "${1:-}" in
    deploy)
        git_pull
        docker exec -w "$BRIDGE_DIR" "$DOCKGE_CONTAINER" docker compose up -d --build
        ;;
    pull)
        git_pull
        ;;
    build)
        docker exec -w "$BRIDGE_DIR" "$DOCKGE_CONTAINER" docker compose up -d --build
        ;;
    logs)
        docker logs -f bridge
        ;;
    help|--help|-h|"")
        echo "Usage: $0 <command>"
        echo ""
        echo "Commands:"
        echo "  deploy   git pull + rebuild bridge container"
        echo "  pull     git pull only"
        echo "  build    rebuild bridge container only"
        echo "  logs     tail bridge container logs"
        ;;
    *)
        echo "Unknown command: $1"
        echo "Run '$0 help' for usage."
        exit 1
        ;;
esac
