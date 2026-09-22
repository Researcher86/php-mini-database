.PHONY: up down shell build install test bench analyse lint fix

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

# slow, load-bearing timing assertions - not part of `make test`
bench: up
	docker compose exec php composer bench

analyse: up
	docker compose exec php composer analyse

# apply the formatter and write the changes
fix: up
	docker compose exec php composer format

# check reports without touching anything (what CI runs)
lint: up
	docker compose exec php composer format:check