# Shortcuts for the Docker environment. Run `make help` to list them.
#
# Optional variables:
#   s=<service>   service for logs and shell   (make logs s=tpvs-queue-worker)
#   ARGS="..."    extra arguments for test, pint (make test ARGS="--filter=Login")

COMPOSE      := docker compose
COMPOSE_PROD := docker compose -f compose.yaml -f compose.prod.yaml
TEST_DB      := tpvs_test

s    ?= tpvs-backend
ARGS ?=

.DEFAULT_GOAL := help
.PHONY: help up up-ai down ps logs shell test pint migrate ai-pull prod-config

help: ## List the available commands
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  make %-12s %s\n", $$1, $$2}'

up: ## Build and start the development stack (without Ollama)
	$(COMPOSE) up -d --build

up-ai: ## Build and start the development stack with the ai profile (Ollama)
	$(COMPOSE) --profile ai up -d --build

down: ## Stop and remove the containers (volumes are kept)
	$(COMPOSE) --profile ai down

ps: ## Show every container, including finished one-shot services
	$(COMPOSE) --profile ai ps -a

logs: ## Follow the logs of a service (s=tpvs-backend by default)
	$(COMPOSE) logs -f --tail=100 $(s)

shell: ## Open a shell in a service (s=tpvs-backend by default)
	$(COMPOSE) exec $(s) sh

test: ## Run the backend suite, always against tpvs_test (creates it if missing)
	$(COMPOSE) exec -T tpvs-postgres sh /docker-entrypoint-initdb.d/01-create-test-db.sh
	$(COMPOSE) exec -T -e DB_DATABASE=$(TEST_DB) tpvs-backend php artisan test $(ARGS)

pint: ## Format backend code with Pint (ARGS="--test app/Foo.php" to check files)
	$(COMPOSE) exec -T tpvs-backend vendor/bin/pint $(ARGS)

migrate: ## Apply pending migrations with the one-shot tpvs-migrate service
	$(COMPOSE) run --rm tpvs-migrate

ai-pull: ## Download the configured Ollama model (ai profile)
	$(COMPOSE) --profile ai run --rm tpvs-ollama-pull

prod-config: ## Validate and print the merged production configuration
	$(COMPOSE_PROD) config
