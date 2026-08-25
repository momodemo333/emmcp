# emMCP - Makefile for Release Management
# ==========================================
#
# Builds a fully self-contained ZIP: the embedded dolibarr-mcp-server package
# and its Composer dependencies are bundled under vendor/, so the client just
# unzips into htdocs/custom/ — nothing to install, no ReactPHP, no daemon.

# Configuration
MODULE_NAME := emmcp
MODULE_FILE := core/modules/modEmmcp.class.php
RELEASE_DIR := /home/morgan/project/dolibarr/releases
BUILD_DIR := build

# Source of the embedded MCP server package (its own upstream repo, embedded
# in the Dalfred module in this workspace). Overridable:
#   make build-release MCP_PACKAGE_SRC=/path/to/dolibarr-mcp-server
MCP_PACKAGE_SRC ?= ../dalfred/dolibarr-mcp-server

# Source of the embedded dolibarr-mcp-oauth library (single upstream repo).
# Overridable: make build-release LIB_OAUTH_SRC=/path/to/dolibarr-mcp-oauth
LIB_OAUTH_SRC ?= ../../../../../dolibarr-mcp-oauth
# Source of the embedded dolibarr-mcp-sql library (read-only SQL over MCP),
# shared with Dalfred. Overridable: make build-release LIB_SQL_SRC=/path/…
LIB_SQL_SRC ?= ../../../../../dolibarr-mcp-sql

# Extract version from the module descriptor
VERSION := $(shell grep -oP "\\\$$this->version\s*=\s*'\K[^']+" $(MODULE_FILE))

# Release filename format for DoliStore: module_packagename-x.y.z.zip
RELEASE_FILENAME := module_$(MODULE_NAME)-$(VERSION).zip

# Entrypoints that MUST be present in the built package (sanity check)
CRITICAL_FILES := mcp.php oauth.php .htaccess \
	core/modules/modEmmcp.class.php \
	admin/setup.php admin/sql_access.php lib/emmcp.lib.php \
	sql/llx_emmcp_oauth_token.sql \
	sql/llx_emmcp_sql_audit.sql \
	sql/llx_emmcp_sql_permissions.sql \
	class/emmcpmigrations.class.php \
	vendor/dolibarr-mcp-sql/src/SqlCapability.php \
	vendor/dolibarr-mcp-sql/src/SqlGateway.php \
	vendor/dolibarr-mcp-sql/src/SqlPermissions.php \
	vendor/dolibarr-mcp-sql/src/SqlAudit.php \
	vendor/dolibarr-mcp-sql/src/SqlConfig.php \
	vendor/dolibarr-mcp-server/LLM.md \
	vendor/dolibarr-mcp-server/vendor/autoload.php \
	vendor/dolibarr-mcp-server/src/Sql/SqlReadOnlyValidator.php \
	vendor/dolibarr-mcp-server/src/Tools/Gated/SqlTools.php \
	vendor/dolibarr-mcp-server/vendor/greenlion/php-sql-parser/src/PHPSQLParser/PHPSQLParser.php \
	lib/emmcp_bootstrap.php \
	vendor/dolibarr-mcp-oauth/src/OAuthServer.php \
	vendor/dolibarr-mcp-oauth/src/HttpEndpoint.php \
	vendor/dolibarr-mcp-oauth/src/OAuthRouter.php

# Colors
GREEN := \033[0;32m
YELLOW := \033[0;33m
RED := \033[0;31m
NC := \033[0m

.PHONY: help version lint build-release check-git-clean tag release publish release-and-publish clean

help:
	@echo "emMCP Release Management"
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

# Version of the sibling dolibarr-mcp-server checkout this module expects to
# bundle. The build refuses to package anything else: the package is a separate
# repository, so without this the ZIP silently carries whatever happens to be
# checked out — a work-in-progress branch, or a stale tree.
EXPECTED_RUNTIME_VERSION ?= 2.3.0

# Same for the shared OAuth library: it is a third separate repository, and the
# build would otherwise bundle whatever is checked out beside it.
#
# Unlike the runtime, this one is pinned even for a dev build: there is no
# feature branch in flight here, so "clean tree at the right version" would
# still accept an arbitrary commit that merely happens to be clean. HEAD must
# be the tag itself. Set EXPECTED_OAUTH_COMMIT to accept a specific untagged
# commit instead — explicitly, never by default.
EXPECTED_OAUTH_VERSION ?= 1.0.0
EXPECTED_OAUTH_COMMIT ?=

# Fixed timestamp for every entry in the ZIP. Any constant works; what matters
# is that it does not change between builds of the same sources.
#
# Note that the checksum legitimately changes when the embedded runtime is
# re-committed: Composer records the package's git HEAD in
# vendor/composer/installed.php, so the ZIP identifies the exact runtime it
# carries. Reproducibility here means "same sources, same checksum", which is
# what makes the checksum worth publishing.
SOURCE_DATE_EPOCH ?= 1700000000

# Set to the tag the runtime must be built from. Left empty, a clean working
# tree at the expected version is accepted, which is what dev builds need. The
# release target sets it, so a published ZIP can only come from a tagged commit.
REQUIRED_RUNTIME_TAG ?=

check-runtime:
	@test -d "$(MCP_PACKAGE_SRC)" || (echo "$(RED)MCP package source not found: $(MCP_PACKAGE_SRC)$(NC)" && exit 1)
	@actual=$$(grep -oE "setServerInfo\('Dolibarr MCP Server', '[^']+'" $(MCP_PACKAGE_SRC)/src/Bootstrap.php | grep -oE "'[0-9][^']*'$$" | tr -d "'"); \
	if [ "$$actual" != "$(EXPECTED_RUNTIME_VERSION)" ]; then \
		echo "$(RED)Runtime version mismatch: expected $(EXPECTED_RUNTIME_VERSION), found $$actual$(NC)"; \
		echo "$(YELLOW)Point MCP_PACKAGE_SRC at the right checkout, or set EXPECTED_RUNTIME_VERSION.$(NC)"; \
		exit 1; \
	fi
	@cd $(MCP_PACKAGE_SRC) && \
	if [ -n "$$(git status --porcelain)" ]; then \
		echo "$(RED)Runtime checkout is dirty; commit or stash before building.$(NC)"; \
		git status --short; \
		exit 1; \
	fi
	@if [ -n "$(REQUIRED_RUNTIME_TAG)" ]; then \
		cd $(MCP_PACKAGE_SRC) && \
		if ! git describe --exact-match --tags HEAD 2>/dev/null | grep -qx "$(REQUIRED_RUNTIME_TAG)"; then \
			echo "$(RED)Runtime HEAD is not at tag $(REQUIRED_RUNTIME_TAG).$(NC)"; \
			echo "$(YELLOW)Merge and tag the runtime first, then build from the tag.$(NC)"; \
			exit 1; \
		fi; \
		echo "  runtime pinned to tag $(REQUIRED_RUNTIME_TAG)"; \
	fi
	@echo "  runtime $(EXPECTED_RUNTIME_VERSION) verified ($(MCP_PACKAGE_SRC))"
.PHONY: check-runtime

check-oauth:
	@test -d "$(LIB_OAUTH_SRC)/src" || (echo "$(RED)dolibarr-mcp-oauth source not found: $(LIB_OAUTH_SRC)$(NC)" && exit 1)
	@cd $(LIB_OAUTH_SRC) && \
	if [ -n "$$(git status --porcelain)" ]; then \
		echo "$(RED)dolibarr-mcp-oauth checkout is dirty; commit or stash before building.$(NC)"; \
		git status --short; \
		exit 1; \
	fi
	@cd $(LIB_OAUTH_SRC) && \
	if [ -n "$(EXPECTED_OAUTH_COMMIT)" ]; then \
		head=$$(git rev-parse HEAD); \
		if [ "$$head" != "$(EXPECTED_OAUTH_COMMIT)" ]; then \
			echo "$(RED)dolibarr-mcp-oauth HEAD is not the expected commit.$(NC)"; \
			echo "$(YELLOW)expected $(EXPECTED_OAUTH_COMMIT), found $$head$(NC)"; \
			exit 1; \
		fi; \
		echo "  oauth library pinned to commit $(EXPECTED_OAUTH_COMMIT)"; \
	else \
		if ! git describe --exact-match --tags HEAD 2>/dev/null | grep -qx "v$(EXPECTED_OAUTH_VERSION)"; then \
			echo "$(RED)dolibarr-mcp-oauth HEAD is not at tag v$(EXPECTED_OAUTH_VERSION).$(NC)"; \
			echo "$(YELLOW)Check out that tag, or set EXPECTED_OAUTH_COMMIT to accept another commit.$(NC)"; \
			exit 1; \
		fi; \
		echo "  oauth library v$(EXPECTED_OAUTH_VERSION) verified at its tag ($(LIB_OAUTH_SRC))"; \
	fi
.PHONY: check-oauth

build-release: check-runtime check-oauth
	@echo "$(GREEN)Building emMCP v$(VERSION)...$(NC)"
	@test -d "$(MCP_PACKAGE_SRC)" || (echo "$(RED)MCP package source not found: $(MCP_PACKAGE_SRC)$(NC)" && exit 1)
	@rm -rf $(BUILD_DIR)
	@mkdir -p $(RELEASE_DIR)
	@mkdir -p $(BUILD_DIR)/$(MODULE_NAME)

	@echo "[1/7] Copying module files..."
	@cp -r admin class core lib sql langs $(BUILD_DIR)/$(MODULE_NAME)/
	@cp mcp.php oauth.php README.md CHANGELOG.md .htaccess $(BUILD_DIR)/$(MODULE_NAME)/

	@echo "[2/7] Bundling dolibarr-mcp-server package..."
	@mkdir -p $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-server
	@cp -r $(MCP_PACKAGE_SRC)/src $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-server/
	@cp $(MCP_PACKAGE_SRC)/LLM.md $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-server/
	@cp $(MCP_PACKAGE_SRC)/composer.json $(MCP_PACKAGE_SRC)/composer.lock $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-server/

	@echo "[3/7] Bundling dolibarr-mcp-oauth library..."
	@test -d "$(LIB_OAUTH_SRC)/src" || (echo "$(RED)dolibarr-mcp-oauth source not found: $(LIB_OAUTH_SRC)$(NC)" && exit 1)
	@mkdir -p $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-oauth
	@cp -r $(LIB_OAUTH_SRC)/src $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-oauth/
	@cp $(LIB_OAUTH_SRC)/composer.json $(LIB_OAUTH_SRC)/README.md $(LIB_OAUTH_SRC)/CHANGELOG.md $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-oauth/

	# Same arrangement for dolibarr-mcp-sql: without it in the package the SQL
	# tools stay hidden on a customer install, since the loader finds no library.
	@echo "[3b/7] Bundling dolibarr-mcp-sql library..."
	@test -d "$(LIB_SQL_SRC)/src" || (echo "$(RED)dolibarr-mcp-sql source not found: $(LIB_SQL_SRC)$(NC)" && exit 1)
	@mkdir -p $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-sql
	@cp -r $(LIB_SQL_SRC)/src $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-sql/
	@cp $(LIB_SQL_SRC)/composer.json $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-sql/

	@echo "[4/7] Installing production Composer dependencies (--no-dev)..."
	@cd $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-server && \
		composer install --no-dev --optimize-autoloader --no-interaction --quiet
# Composer installs in parallel, so installed.json ends up ordered by whichever
# download finished first, and the generated autoloaders inherit that order.
# Two clean clones therefore produced different ZIPs. Sort the source, then let
# Composer regenerate from it.
	@php tools/normalize-vendor.php $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-server
	@cd $(BUILD_DIR)/$(MODULE_NAME)/vendor/dolibarr-mcp-server && \
		composer dump-autoload --no-dev --optimize --no-interaction --quiet

	@echo "[5/7] Pruning dev cruft..."
	@find $(BUILD_DIR)/$(MODULE_NAME) -name ".git" -type d -exec rm -rf {} + 2>/dev/null || true
	@find $(BUILD_DIR)/$(MODULE_NAME) -type f \( -name ".gitignore" -o -name ".gitattributes" \) -delete 2>/dev/null || true
	@find $(BUILD_DIR)/$(MODULE_NAME)/vendor -type d \( -name tests -o -name ".github" -o -name docs -o -name ".phan" -o -name examples -o -name wiki -o -name ".settings" \) -exec rm -rf {} + 2>/dev/null || true
	@find $(BUILD_DIR)/$(MODULE_NAME)/vendor -type f \( -name "phpunit.xml*" -o -name "phpstan.neon" -o -name ".editorconfig" -o -name "*.dist" \) -delete 2>/dev/null || true

	@echo "[6/7] Verifying critical files are present..."
	@for f in $(CRITICAL_FILES); do \
		if [ ! -e "$(BUILD_DIR)/$(MODULE_NAME)/$$f" ]; then \
			echo "$(RED)MISSING from build: $$f$(NC)"; exit 1; \
		fi; \
	done
	@echo "  all $(words $(CRITICAL_FILES)) critical files present"

	@echo "[7/7] Creating ZIP (reproducible)..."
	@rm -f $(RELEASE_DIR)/$(RELEASE_FILENAME) $(RELEASE_DIR)/$(RELEASE_FILENAME).sha256
# Two builds of the same sources must yield the same checksum, otherwise the
# hash proves nothing about what is inside. Three things break that: file
# mtimes, the order entries are added in, and the extra attributes zip stores
# by default. All three are pinned here.
	@find $(BUILD_DIR) -exec touch -h -d "@$(SOURCE_DATE_EPOCH)" {} + 2>/dev/null || \
		find $(BUILD_DIR) -exec touch -d "@$(SOURCE_DATE_EPOCH)" {} +
	@cd $(BUILD_DIR) && find $(MODULE_NAME) -print | LC_ALL=C sort | \
		zip -qX -@ $(RELEASE_DIR)/$(RELEASE_FILENAME)
	@cd $(RELEASE_DIR) && sha256sum $(RELEASE_FILENAME) > $(RELEASE_FILENAME).sha256
	@rm -rf $(BUILD_DIR)
	@echo ""
	@echo "$(GREEN)Release built:$(NC)"
	@ls -lh $(RELEASE_DIR)/$(RELEASE_FILENAME)
	@cat $(RELEASE_DIR)/$(RELEASE_FILENAME).sha256

# Builds twice into a scratch copy and compares. Kept as a target so the claim
# can be re-checked on any machine rather than taken on trust.
verify-reproducible:
	@$(MAKE) build-release >/dev/null
	@cp $(RELEASE_DIR)/$(RELEASE_FILENAME) $(RELEASE_DIR)/.repro-check.zip
	@$(MAKE) build-release >/dev/null
	@if cmp -s $(RELEASE_DIR)/.repro-check.zip $(RELEASE_DIR)/$(RELEASE_FILENAME); then \
		echo "$(GREEN)Reproducible: two builds are byte-identical.$(NC)"; \
		rm -f $(RELEASE_DIR)/.repro-check.zip; \
	else \
		echo "$(RED)Not reproducible: the two builds differ.$(NC)"; \
		rm -f $(RELEASE_DIR)/.repro-check.zip; \
		exit 1; \
	fi
.PHONY: verify-reproducible

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

# A published ZIP may only be built from tagged dependencies.
#
# Expected versions: runtime $(EXPECTED_RUNTIME_VERSION), oauth library
# $(EXPECTED_OAUTH_VERSION). Order of operations:
#   1. merge + push dolibarr-mcp-server, then tag it v$(EXPECTED_RUNTIME_VERSION)
#   2. check out dolibarr-mcp-oauth at its tag v$(EXPECTED_OAUTH_VERSION)
#   3. merge + push emMCP
#   4. make release   (checks both deps, builds and verifies, and only then
#                      tags emMCP and rebuilds from the tag)
#   5. make publish
release: check-git-clean
# Nothing is tagged or pushed until a verified build exists. `tag` pushes to
# origin, so tagging first meant a failed or non-reproducible build could leave
# a published release tag pointing at something that never shipped — and a
# pushed tag is the one step here that cannot be quietly undone.
	@echo "$(GREEN)[1/4] Verifying pinned dependencies...$(NC)"
	@$(MAKE) REQUIRED_RUNTIME_TAG=v$(EXPECTED_RUNTIME_VERSION) check-runtime
	@$(MAKE) check-oauth

	@echo "$(GREEN)[2/4] Building and proving reproducibility...$(NC)"
	@$(MAKE) REQUIRED_RUNTIME_TAG=v$(EXPECTED_RUNTIME_VERSION) verify-reproducible
	@cp $(RELEASE_DIR)/$(RELEASE_FILENAME) $(RELEASE_DIR)/.pre-tag.zip

	@echo "$(GREEN)[3/4] Tagging emMCP...$(NC)"
	@$(MAKE) tag

	@echo "$(GREEN)[4/4] Rebuilding from the tagged tree...$(NC)"
	@$(MAKE) REQUIRED_RUNTIME_TAG=v$(EXPECTED_RUNTIME_VERSION) build-release
	@if cmp -s $(RELEASE_DIR)/.pre-tag.zip $(RELEASE_DIR)/$(RELEASE_FILENAME); then \
		echo "  the tagged tree produces the byte-identical ZIP"; \
		rm -f $(RELEASE_DIR)/.pre-tag.zip; \
	else \
		echo "$(RED)The tagged tree produced a different ZIP.$(NC)"; \
		rm -f $(RELEASE_DIR)/.pre-tag.zip; \
		exit 1; \
	fi
	@echo "$(GREEN)Release v$(VERSION) complete.$(NC)"

publish: ## Publish latest release to EMGateway
	@EMGATEWAY_MODULE_DIR=$(CURDIR) /home/morgan/project/dolibarr/scripts/publish-to-gateway.sh $(RELEASE_DIR)/$(RELEASE_FILENAME)

release-and-publish: release publish

clean:
	@rm -rf $(BUILD_DIR)
	@echo "$(GREEN)Build artifacts cleaned. Release files in $(RELEASE_DIR)/ preserved.$(NC)"
