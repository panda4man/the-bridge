#!/bin/sh
set -e

BRIDGE_DIR="$(cd "$(dirname "$0")" && pwd)"
DOCKGE_CONTAINER="dockge"

case "${1:-}" in
    deploy)
        git -C "$BRIDGE_DIR" pull
        docker exec -w "$BRIDGE_DIR" "$DOCKGE_CONTAINER" docker compose up -d --build
        ;;
    pull)
        git -C "$BRIDGE_DIR" pull
        ;;
    build)
        docker exec -w "$BRIDGE_DIR" "$DOCKGE_CONTAINER" docker compose up -d --build
        ;;
    logs)
        docker logs -f bridge
        ;;
    *)
        echo "Usage: $0 {deploy|pull|build|logs}"
        exit 1
        ;;
esac
