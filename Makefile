.PHONY: setup build run stop logs test clean

setup:
	@test -f .env || cp .env.example .env
	@grep -q '^APP_KEY=base64:' .env || sed -i.bak "s|^APP_KEY=.*|APP_KEY=base64:$$(openssl rand -base64 32)|" .env && rm -f .env.bak
	@echo ".env is ready"

build:
	docker compose build

run: setup
	docker compose up -d --build
	@echo "App: http://localhost:10000/up"

stop:
	docker compose down

logs:
	docker compose logs -f app

test:
	docker compose exec app php artisan route:list

clean:
	docker compose down -v --rmi local
