BRIDGE_DIR     := $(shell pwd)
DOCKGE_CONTAINER := dockge

.PHONY: help deploy pull build logs

help:
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@echo "  deploy   git pull + rebuild bridge container"
	@echo "  pull     git pull only"
	@echo "  build    rebuild bridge container only"
	@echo "  logs     tail bridge container logs"

deploy: pull build

pull:
	git pull

build:
	docker exec -w $(BRIDGE_DIR) $(DOCKGE_CONTAINER) docker compose up -d --build

logs:
	docker logs -f bridge
