# Ticket Reservation API — everything runs in Docker.
# The only prerequisite on a clean clone is Docker with the compose plugin.

COMPOSE := docker compose
EXEC    := $(COMPOSE) exec -T app
RUN     := $(COMPOSE) run --rm --no-deps -T app

.PHONY: up down fresh test logs shell fake-webhook env wait-app

## Bring the whole stack up from a clean clone and leave it ready to use.
up: env
	$(COMPOSE) build
	$(RUN) composer install --no-interaction --prefer-dist --no-progress
	$(RUN) sh -c 'grep -q "^APP_KEY=base64:" .env || php artisan key:generate --force --no-interaction'
# app (and its mysql dependency) only: the scheduler must not tick against
# a database that has not been migrated yet. It joins on the `up -d` below.
	$(COMPOSE) up -d app
	$(MAKE) wait-app
	$(EXEC) php artisan migrate --force
	$(EXEC) php artisan db:seed --force
	$(COMPOSE) up -d
	@echo
	@echo "Ready: http://localhost:8000/up"

## Create .env from the template on first run. .env is gitignored; .env.testing is committed.
env:
	@test -f .env || (cp .env.example .env && echo "created .env from .env.example")

## Block until the app container reports healthy (curl -f http://localhost:8000/up).
wait-app:
	@echo "waiting for app health ..."
	@i=0; \
	while [ $$i -lt 120 ]; do \
	  cid=`$(COMPOSE) ps -q app`; \
	  status=`docker inspect -f "{{.State.Health.Status}}" $$cid 2>/dev/null || echo starting`; \
	  if [ "$$status" = "healthy" ]; then echo "app is healthy"; exit 0; fi; \
	  if [ "$$status" = "unhealthy" ]; then $(COMPOSE) logs --tail=50 app; echo "app is unhealthy"; exit 1; fi; \
	  i=`expr $$i + 1`; sleep 2; \
	done; \
	$(COMPOSE) logs --tail=50 app; echo "timed out waiting for app health"; exit 1

## Stop the stack (keeps the mysql volume).
down:
	$(COMPOSE) down

## Rebuild the application database from scratch and reseed it.
fresh:
	$(EXEC) php artisan migrate:fresh --seed --force

## Run the Pest suite against the tickets_test database (never SQLite).
## Waits for GET /up first, so `make test` on a cold stack reports failures
## rather than a container that had not finished booting.
test: wait-app
	$(EXEC) php artisan config:clear
	$(EXEC) env APP_ENV=testing DB_DATABASE=tickets_test vendor/bin/pest

## Tail the logs of every service.
logs:
	$(COMPOSE) logs -f

## Interactive shell in the app container.
shell:
	$(COMPOSE) exec app bash

## Fire a signed fake payment webhook at the running app, the way the provider
## would. Usage: make fake-webhook ID=<payment ulid> [TYPE=succeeded|failed|expired]
TYPE ?= succeeded

fake-webhook:
	@test -n "$(ID)" || (echo "usage: make fake-webhook ID=<payment ulid> [TYPE=succeeded|failed|expired]" && exit 1)
	$(EXEC) php artisan payments:fake-webhook $(ID) --type=$(TYPE)
