BRIDGE_DIR     := $(shell pwd)
DOCKGE_CONTAINER := dockge

.PHONY: deploy pull build logs

deploy: pull build

pull:
	git pull

build:
	docker exec -w $(BRIDGE_DIR) $(DOCKGE_CONTAINER) docker compose up -d --build

logs:
	docker logs -f bridge
