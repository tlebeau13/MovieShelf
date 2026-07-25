# MovieShelf — one entry point for the four components.
#
# Nothing here is required: every target is a shortcut for a command you could
# type by hand. The value is that the awkward ones (which role psql connects as,
# which container a test runs in) stop being things you have to remember.

SHELL := /bin/bash
.DEFAULT_GOAL := help

# Compose derives volume names from the project name, which is the lowercased
# directory name unless COMPOSE_PROJECT_NAME says otherwise.
PROJECT ?= movieshelf

# Credentials live in .env (gitignored). Targets that need them fail loudly
# rather than silently connecting as the wrong role.
-include .env
export

COMPOSE := docker compose

.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

# ── Stack ────────────────────────────────────────────────────────────────────

.PHONY: up
up: ## Start every service in the background
	$(COMPOSE) up -d

.PHONY: down
down: ## Stop every service (volumes and data survive)
	$(COMPOSE) down

.PHONY: restart
restart: ## Restart every service
	$(COMPOSE) restart

.PHONY: build
build: ## Rebuild all images
	$(COMPOSE) build

.PHONY: ps
ps: ## Show service status
	$(COMPOSE) ps

.PHONY: logs
logs: ## Tail logs for all services (make logs S=analytics for one)
	$(COMPOSE) logs -f $(S)

.PHONY: health
health: ## Hit every local endpoint and print status codes
	@printf '%-42s %s\n' \
		'symfony  https://localhost/health' "$$(curl -sk -o /dev/null -w '%{http_code}' https://localhost/health)" \
		'next     http://localhost:3000/'   "$$(curl -s  -o /dev/null -w '%{http_code}' http://localhost:3000/)" \
		'stub     /api/stub/rank-series'    "$$(curl -s  -o /dev/null -w '%{http_code}' http://localhost:3000/api/stub/rank-series)"
	@echo
	@curl -sk https://localhost/health

# ── api/ (Symfony) ───────────────────────────────────────────────────────────

.PHONY: api-shell
api-shell: ## Open a shell in the php container
	$(COMPOSE) exec php bash

.PHONY: api-console
api-console: ## Run a Symfony console command: make api-console CMD="debug:router"
	$(COMPOSE) exec php bin/console $(CMD)

.PHONY: api-composer
api-composer: ## Run composer: make api-composer CMD="require symfony/uid"
	$(COMPOSE) exec php composer $(CMD)

.PHONY: api-routes
api-routes: ## List Symfony routes
	$(COMPOSE) exec php bin/console debug:router

.PHONY: migrate
migrate: ## Apply pending Doctrine migrations
	# Runs as the `symfony` role, which is what makes the ALTER DEFAULT
	# PRIVILEGES grants to `analytics` fire. Never run migrations as superuser.
	$(COMPOSE) exec php bin/console doctrine:migrations:migrate --no-interaction

.PHONY: migration
migration: ## Generate a migration from entity changes
	$(COMPOSE) exec php bin/console make:migration

# ── analytics/ (Python) ──────────────────────────────────────────────────────

.PHONY: analytics-shell
analytics-shell: ## Open a shell in the analytics container
	$(COMPOSE) exec analytics bash

.PHONY: analytics-run
analytics-run: ## Run the hello job now, without waiting for cron
	$(COMPOSE) exec analytics hello

.PHONY: analytics-test
analytics-test: ## Run pytest in the container (needs the live DB)
	$(COMPOSE) exec analytics python -m pytest

.PHONY: analytics-lint
analytics-lint: ## Run ruff
	$(COMPOSE) exec analytics ruff check .

.PHONY: analytics-venv
analytics-venv: ## Build the host venv so the editor resolves imports
	cd analytics && uv sync --dev

# ── web/ (Next.js) ───────────────────────────────────────────────────────────

.PHONY: web-dev
web-dev: ## Start the Next dev server on :3000
	cd web && yarn dev

.PHONY: web-build
web-build: ## Production build
	cd web && yarn build

.PHONY: web-lint
web-lint: ## Run eslint
	cd web && yarn lint

.PHONY: web-types
web-types: ## Typecheck without emitting
	cd web && ./node_modules/.bin/tsc --noEmit

.PHONY: web-install
web-install: ## Install JS dependencies
	cd web && yarn install

# ── db/ ──────────────────────────────────────────────────────────────────────

.PHONY: psql
psql: ## psql as the superuser
	$(COMPOSE) exec db psql -U $(POSTGRES_USER) -d $(POSTGRES_DB)

.PHONY: psql-symfony
psql-symfony: ## psql as the `symfony` role (RW raw+canonical, RO mart)
	$(COMPOSE) exec db psql "postgresql://symfony:$(SYMFONY_DB_PASSWORD)@localhost:5432/$(POSTGRES_DB)"

.PHONY: psql-analytics
psql-analytics: ## psql as the `analytics` role (RO raw+canonical, RW mart)
	$(COMPOSE) exec db psql "postgresql://analytics:$(ANALYTICS_DB_PASSWORD)@localhost:5432/$(POSTGRES_DB)"

.PHONY: db-grants
db-grants: ## Show who can write what (the contract, as the DB sees it)
	@$(COMPOSE) exec db psql -U $(POSTGRES_USER) -d $(POSTGRES_DB) -c "\
		SELECT table_schema, grantee, string_agg(DISTINCT privilege_type, ', ' ORDER BY privilege_type) AS privileges \
		FROM information_schema.role_table_grants \
		WHERE table_schema IN ('raw','canonical','mart') \
		GROUP BY table_schema, grantee ORDER BY table_schema, grantee;"

.PHONY: db-reset
db-reset: ## DESTRUCTIVE: drop the database volume and re-run db/init
	@# Only the pgdata volume, deliberately. `docker compose down -v` would also
	@# drop caddy_data/caddy_config, which come back root-owned and then break
	@# the non-root php container with a TLS permission error.
	@printf 'This deletes ALL database contents (volume $(PROJECT)_pgdata). Type "yes" to continue: '; \
	read -r answer; \
	if [ "$$answer" != "yes" ]; then echo "aborted"; exit 1; fi; \
	$(COMPOSE) down; \
	docker volume rm $(PROJECT)_pgdata; \
	$(COMPOSE) up -d db

# ── Everything ───────────────────────────────────────────────────────────────

.PHONY: check
check: analytics-lint analytics-test web-types web-lint ## Run every lint and test suite
	@echo "all checks passed"
