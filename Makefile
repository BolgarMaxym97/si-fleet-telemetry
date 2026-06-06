.PHONY: up down build logs migrate fresh consume shell psql redis-cli test

up:            ## build + start the stack (creates the shared network if absent)
	docker network create fleet-shared 2>/dev/null || true
	docker compose up -d --build

down:
	docker compose down

build:
	docker compose build

logs:          ## tail consumer + app
	docker compose logs -f consumer app

migrate:
	docker compose exec app php artisan migrate --force

fresh:
	docker compose exec app php artisan migrate:fresh --force

consume:       ## run the consumer in the foreground (debug)
	docker compose exec app php artisan kafka:consume-pings

shell:
	docker compose exec app sh

psql:
	docker compose exec timescaledb psql -U fleet -d fleet

redis-cli:
	docker compose exec redis redis-cli

test:
	docker compose exec app php artisan test
