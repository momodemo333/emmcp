# DoliMCP - Makefile for Release Management
# ==========================================
#
# Builds a fully self-contained ZIP: the embedded dolibarr-mcp-server package
# and its Composer dependencies are bundled under vendor/, so the client just
# unzips into htdocs/custom/ — nothing to install, no ReactPHP, no daemon.

# Configuration
MODULE_NAME := dolimcp
MODULE_FILE := core/modules/modDoliMcp.class.php
RELEASE_DIR := /home/morgan/project/dolibarr/releases
BUILD_DIR := build

# Source of the embedded MCP server package (its own upstream repo, embedded
# in the Dalfred module in this workspace). Overridable:
#   make build-release MCP_PACKAGE_SRC=/path/to/dolibarr-mcp-server
MCP_PACKAGE_SRC ?= ../dalfred/dolibarr-mcp-server

# Extract version from the module descriptor
VERSION := $(shell grep -oP "\\\$$this->version\s*=\s*'\K[^']+" $(MODULE_FILE))

# Release filename format for DoliStore: module_packagename-x.y.z.zip
RELEASE_FILENAME := module_$(MODULE_NAME)-$(VERSION).zip

# Entrypoints that MUST be present in the built package (sanity check)
CRITICAL_FILES := mcp.php oauth.php \
	core/modules/modDoliMcp.class.php \
	admin/setup.php lib/dolimcp.lib.php \
	class/dolimcpoauthserver.class.php \
	sql/llx_dolimcp_oauth_token.sql \
	vendor/dolibarr-mcp-server/LLM.md \
	vendor/dolibarr-mcp-server/vendor/autoload.php

# Colors
GREEN := \033[0;32m
YELLOW := \033[0;33m
RED := \033[0;31m
NC := \033[0m

.PHONY: help version lint build-release check-git-clean tag release publish release-and-publish clean

help:
	@echo "DoliMCP Release Management"
	@echo "=========================="
	@echo ""
	@echo "Current version: $(VERSION)"
	@echo "Release file:    $(RELEASE_FILENAME)"
	@echo ""
	@echo "  make version             - Show module version"
	@echo "  make lint                - PHP syntax check on module sources"
	@echo "  make build-release       - Build the self-contained ZIP in $(RELEASE_DIR)/"
	@echo "  make tag                 - Create and push git tag v$(VERSION)"
	@echo "  make release             - tag + build-release"
	@echo "  make publish             - Upload the ZIP to EMGateway (product from .emgateway.conf)"
	@echo "  make release-and-publish - release + publish"
	@echo "  make clean               - Remove build artifacts"
	@echo ""

version:
	@echo "$(VERSION)"

lint:
	@find admin class core lib -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
	@php -l mcp.php >/dev/null && php -l oauth.php >/dev/null
	@echo "$(GREEN)PHP syntax OK$(NC)"

build-release:
	@echo "$(GREEN)Building DoliMCP v$(VERSION)...$(NC)"
	@test -d "$(MCP_PACKAGE_SRC)" || (echo "$(RED)MCP package source not found: $(MCP_PACKAGE_SRC)$(NC)" && exit 1)
	@rm -rf $(BUILD_DIR)
	@mkdir -p $(RELEASE_DIR)
	@mkdir -p $(BUILD_DIR)/$(MODULE_NAME)

	@echo "[1/6] Copying module files..."
	@cp -r admin class core lib sql langs $(BUILD_DIR)/$(MODULE_NAME)/
	@cp mcp.php oauth.php README.md CHANGELOG.md $(BUILD_DIR)/$(MODULE_NAME)/

	@echo "[2/6] Bundling dolibarr-mcp-server package..."
	@mkdir -p $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-server
	@cp -r $(MCP_PACKAGE_SRC)/src $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-server/
	@cp $(MCP_PACKAGE_SRC)/LLM.md $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-server/
	@cp $(MCP_PACKAGE_SRC)/composer.json $(MCP_PACKAGE_SRC)/composer.lock $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-server/

	@echo "[3/6] Installing production Composer dependencies (--no-dev)..."
	@cd $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-server && \
		composer install --no-dev --optimize-autoloader --no-interaction --quiet

	@echo "[4/6] Pruning dev cruft..."
	@find $(BUILD_DIR)/$(MODULE_NAME) -name ".git" -type d -exec rm -rf {} + 2>/dev/null || true
	@find $(BUILD_DIR)/$(MODULE_NAME) -type f \( -name ".gitignore" -o -name ".gitattributes" \) -delete 2>/dev/null || true
	@find $(BUILD_DIR)/$(MODULE_NAME)/vendor -type d \( -name tests -o -name ".github" -o -name docs -o -name ".phan" \) -exec rm -rf {} + 2>/dev/null || true
	@find $(BUILD_DIR)/$(MODULE_NAME)/vendor -type f \( -name "phpunit.xml*" -o -name "phpstan.neon" -o -name ".editorconfig" -o -name "*.dist" \) -delete 2>/dev/null || true

	@echo "[5/6] Verifying critical files are present..."
	@for f in $(CRITICAL_FILES); do \
		if [ ! -e "$(BUILD_DIR)/$(MODULE_NAME)/$$f" ]; then \
			echo "$(RED)MISSING from build: $$f$(NC)"; exit 1; \
		fi; \
	done
	@echo "  all $(words $(CRITICAL_FILES)) critical files present"

	@echo "[6/6] Creating ZIP..."
	@rm -f $(RELEASE_DIR)/$(RELEASE_FILENAME) $(RELEASE_DIR)/$(RELEASE_FILENAME).sha256
	@cd $(BUILD_DIR) && zip -rq $(RELEASE_DIR)/$(RELEASE_FILENAME) $(MODULE_NAME)/
	@cd $(RELEASE_DIR) && sha256sum $(RELEASE_FILENAME) > $(RELEASE_FILENAME).sha256
	@rm -rf $(BUILD_DIR)
	@echo ""
	@echo "$(GREEN)Release built:$(NC)"
	@ls -lh $(RELEASE_DIR)/$(RELEASE_FILENAME)
	@cat $(RELEASE_DIR)/$(RELEASE_FILENAME).sha256

check-git-clean:
	@if [ -n "$$(git status --porcelain)" ]; then \
		echo "$(RED)Working directory is not clean. Commit or stash first.$(NC)"; \
		git status --short; exit 1; \
	fi

tag: check-git-clean
	@if git rev-parse "v$(VERSION)" >/dev/null 2>&1; then \
		echo "$(RED)Tag v$(VERSION) already exists.$(NC)"; exit 1; \
	fi
	@git tag -a "v$(VERSION)" -m "Release v$(VERSION)"
	@git push origin "v$(VERSION)" 2>/dev/null || echo "$(YELLOW)No 'origin' remote — tag created locally only.$(NC)"
	@echo "$(GREEN)Tag v$(VERSION) created.$(NC)"

release: tag build-release
	@echo "$(GREEN)Release v$(VERSION) complete.$(NC)"

publish: ## Publish latest release to EMGateway
	@EMGATEWAY_MODULE_DIR=$(CURDIR) /home/morgan/project/dolibarr/scripts/publish-to-gateway.sh $(RELEASE_DIR)/$(RELEASE_FILENAME)

release-and-publish: release publish

clean:
	@rm -rf $(BUILD_DIR)
	@echo "$(GREEN)Build artifacts cleaned. Release files in $(RELEASE_DIR)/ preserved.$(NC)"
