# Deployment Notes — Assignment #3 (VPS Deployment)

Technical notes recorded while deploying the SkillSpan API from local development to a production
VPS using Docker.

- **Student:** Ahmed Saqllah — 120230723
- **Repository:** https://github.com/SkillSpan/Back-End (branch `feature/authentication`)


## Deployment Summary

1. Created the VPS instance and opened the web terminal.
2. Verified Git and Docker, installed them where missing.
3. Cloned the repository on the server.
4. Created `.env` from `.env.example` and generated the application key.
5. Built the image and started the stack with Docker Compose.
6. Opened TCP port 10000 and verified the API from the public URL.

---

## Challenges and Resolutions


### 1. Docker daemon not running

**Problem:** `docker build` failed with `Cannot connect to the Docker daemon`.
**Cause:** the Docker service was not started.
**Resolution:** started and enabled the service, then re-ran the build.

### 2. Container exited immediately after start

**Problem:** the container stopped seconds after `docker run`.
**Cause:** the entrypoint runs `php artisan migrate --force`, which fails when the database is not
reachable yet.
**Resolution:** added a MySQL container to the Compose stack with a health check and made the
application wait for it (`depends_on: condition: service_healthy`).

### 3. Application served but returned 500 errors

**Problem:** every request returned HTTP 500.
**Cause:** `APP_KEY` was empty in `.env`.
**Resolution:** ran `docker compose run --rm --no-deps app php artisan key:generate`.

### 4. Application not reachable from the internet

**Problem:** `curl` worked on the server but the public URL timed out.
**Cause:** inbound TCP 10000 was blocked by the provider's firewall.
**Resolution:** added an inbound rule for TCP 10000 and re-tested from a browser.

---

## Lessons Learned

> **TODO:** two or three sentences — what you would do differently next time, and what surprised you
> about moving an application from local development to a real server.

1. Container Immediate Crash on Startup
   Root Cause: The CMD instruction executed php artisan migrate prior to starting the application server. Because .env.example set the database host to 127.0.0.1, the migration targeted the app container itself rather than the MySQL container, triggering an immediate failure and container exit. Additionally, a missing APP_KEY halted Laravel's bootstrap process.

Resolution:

Configured docker-compose.yml with a dedicated MySQL service and updated DB_HOST=db.

Integrated a database healthcheck paired with depends_on: service_healthy to block the API service from starting until MySQL is fully operational.

Added a make setup command to generate APP_KEY automatically and included an application healthcheck targeting Laravel is built-in /up route.

2. Missing Documentation Files & Git Workflow Lessons
   Root Cause: A broad \*.md pattern inside .gitignore silently excluded README.md and docs/notes.md from tracking while leaving them on the local filesystem.

Best Practices Applied:

Consistently verify git status and inspect remote repositories on GitHub after committing, ensuring ignore rules remain precise.

Keep .env strictly out of source control and commit only .env.example.

Isolate work inside feature branches and merge into main exclusively through Pull Requests to maintain repository cleanliness.
