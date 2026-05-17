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
