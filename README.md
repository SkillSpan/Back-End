# SkillSpan API

REST API backend for **SkillSpan** — a platform that connects learners with companies through
skill-based projects, evaluations, and verified professional records.

Built with **Laravel 12** (PHP 8.3) and **MySQL 8**, and shipped as a **Docker** image so the exact
same build runs on a laptop and on a production VPS.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.3 (CLI image) |
| Framework | Laravel 12 |
| Auth | Laravel Sanctum (bearer tokens) |
| Database | MySQL 8.0 |
| Container | Docker + Docker Compose |
| Docs | OpenAPI / Swagger UI at `/docs` |

---

## Project Structure

```
.
├─ app/                     # application code (controllers, services, models, ...)
├─ bootstrap/               # framework bootstrap + cache
├─ config/                  # configuration files
├─ database/                # migrations, seeders, factories
├─ docs/
│  ├─ screenshots/          # deployment screenshots (Assignment #3)
│  └─ notes.md              # deployment notes and challenges
├─ public/                  # web root
├─ resources/               # views and assets
├─ routes/                  # api.php, web.php, console.php
├─ storage/                 # logs, cache, uploaded files
├─ tests/                   # PHPUnit feature and unit tests
├─ Dockerfile               # production image
├─ docker-compose.yml       # app + MySQL for local / VPS runs
├─ .dockerignore
├─ .gitignore
├─ LICENSE
└─ README.md
```

---

## Requirements

- Docker Engine 20+ (with the Compose plugin) — or Docker Desktop on Windows/macOS
- Git
- No local PHP or MySQL installation is needed; everything runs in containers.

---

## Run Locally

```bash
git clone <repository-url>
cd Back-End

cp .env.example .env
docker compose run --rm --no-deps app php artisan key:generate

docker compose up -d --build
```

The API is then available on **http://localhost:10000**.

Useful commands:

```bash
docker compose ps                  # container status
docker compose logs -f app         # follow application logs
docker compose down                # stop
docker compose down -v             # stop and delete the database volume
```

Health check:

```bash
curl -i http://localhost:10000/up
```

A `401 Unauthorized` JSON response from an API route is expected and proves the stack is running —
those routes require a Sanctum token.

---

## Deploy to a VPS

The steps below assume a fresh **Ubuntu 22.04 / 24.04** server with a public IP.

### 1. Access the server

Connect over SSH (or use the provider's web terminal):

```bash
ssh <user>@<server-ip>
```

### 2. Install Git and Docker

```bash
sudo apt update
sudo apt install -y git docker.io docker-compose-v2
sudo systemctl enable --now docker

git --version
docker --version
```

### 3. Clone the repository

```bash
git clone -b feature/authentication https://github.com/SkillSpan/Back-End.git
cd Back-End
```

### 4. Configure the environment

```bash
cp .env.example .env
nano .env
```

Set at least:



```bash
docker compose run --rm --no-deps app php artisan key:generate
```

> The database password is read from `.env` by `docker-compose.yml`. Keep it out of Git — `.env` is
> already ignored.

### 5. Build and run

```bash
docker compose up -d --build
```

The first build installs Composer dependencies and PHP extensions, so it takes a few minutes.

### 6. Open the port

Allow inbound TCP **10000** in the provider's firewall / security group:

```bash
sudo ufw allow 10000/tcp    # only if ufw is enabled
```

### 7. Verify the deployment

```bash
docker compose ps
docker compose logs --tail=50 app
curl -i http://<server-ip>:10000/up
```

Then open `http://<server-ip>:10000/docs` in a browser.

---

## Production URL

> **TODO:** replace the placeholders below once the deployment is live.




## Deployment Notes

Challenges faced during the VPS deployment and how they were resolved are documented in
[`docs/notes.md`](docs/notes.md).

---

## License

Released under the [MIT License](LICENSE).
