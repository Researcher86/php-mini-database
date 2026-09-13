.PHONY: up down shell build install test analyse lint fix

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

shell: up
	docker compose exec php bash

htop: up
	docker compose exec php htop

install: up
	docker compose exec php composer install

test: up
	docker compose exec php composer test

analyse: up
	docker compose exec php composer analyse

# apply the formatter and write the changes
fix: up
	docker compose exec php composer format

# check reports without touching anything (what CI runs)
lint: up
	docker compose exec php composer format:check