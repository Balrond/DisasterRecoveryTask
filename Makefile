SHELL := /bin/sh

PHP_CONTAINER := drs_php
APP_DIR := app

# Pass extra args like:
#   make sf ARGS="app:debug-tier C009 2024-01-10"
#   make composer ARGS="require --dev symfony/test-pack"
ARGS ?=

.PHONY: up down sh logs install composer sf test reset-db import discrepancies calc-fee debug-tier test-rate

up:
	docker compose up -d --build

down:
	docker compose down -v

logs:
	docker compose logs -f --tail=200

sh:
	docker exec -it $(PHP_CONTAINER) sh

install:
	docker exec -it $(PHP_CONTAINER) sh -lc "cd $(APP_DIR) && composer install"

composer:
	docker exec -it $(PHP_CONTAINER) sh -lc "cd $(APP_DIR) && composer $(ARGS)"

sf:
	docker exec -it $(PHP_CONTAINER) sh -lc "cd $(APP_DIR) && php bin/console $(ARGS)"

test:
	docker exec -it $(PHP_CONTAINER) sh -lc "cd $(APP_DIR) && ./vendor/bin/phpunit"

reset-db:
	docker exec -it $(PHP_CONTAINER) sh -lc "cd $(APP_DIR) && php bin/console doctrine:database:drop --force --if-exists && php bin/console doctrine:database:create && php bin/console doctrine:migrations:migrate -n"

import:
	docker exec -it $(PHP_CONTAINER) sh -lc "cd $(APP_DIR) && php bin/console app:import-csv"

discrepancies:
	docker exec -it $(PHP_CONTAINER) sh -lc "cd $(APP_DIR) && php bin/console app:discrepancy-report"

# Convenience wrappers (optional)
calc-fee:
	docker exec -it $(PHP_CONTAINER) sh -lc "cd $(APP_DIR) && php bin/console app:calculate-fee $(ARGS)"

debug-tier:
	docker exec -it $(PHP_CONTAINER) sh -lc "cd $(APP_DIR) && php bin/console app:debug-tier $(ARGS)"

test-rate:
	docker exec -it $(PHP_CONTAINER) sh -lc "cd $(APP_DIR) && php bin/console app:test-rate $(ARGS)"
