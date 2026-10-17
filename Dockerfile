FROM php:8.2-cli

# Install system dependencies and PHP extensions Laravel needs
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql mbstring zip gd opcache \
    && rm -rf /var/lib/apt/lists/*

# OPcache — reuse compiled bytecode instead of re-parsing every file on every
# request. This is the largest warm-path win on this deployment: `artisan serve`
# runs the **CLI SAPI**, where opcache is disabled by default
# (`opcache.enable_cli=0`), so without this file each request recompiles the
# framework and the whole vendor tree.
#
# `validate_timestamps=0` is safe because the code cannot change inside a
# running container — a deploy builds a new image. It also removes a stat() per
# included file. `max_accelerated_files=20000` is above Laravel's file count
# (the default of 10000 would silently stop caching part of the app).
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=24'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/zz-opcache.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first (better build caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy the rest of the application
COPY . .

# Finish composer setup now that all files are present.
# --no-scripts avoids running `php artisan package:discover` during the build,
# which would boot Laravel before APP_KEY exists in the build environment.
RUN composer dump-autoload --optimize --no-scripts \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 10000

# Render سيت $PORT؛ لازم البورت ينفتح فورًا وإلا Render بيعتبر الديبلوي
# فشل (port-scan timeout) حتى لو التطبيق شغال صح جواه. الـ migrations
# سريعة فعلاً (ثواني) فتضل قبل السيرفر، بس الـ seeding (بيانات مرجعية:
# دول، جامعات، عشرات آلاف السطور) منشغّله بالخلفية (&) *بعد* ما السيرفر
# يبلش يستمع، مش قبله — هيك Render بيشوف البورت مفتوح فورًا، والسيدينج
# بيكمل بهدوء بدون ما يوقف أي شي.
#
# REGRESSION (fixed 2026-09-25): the seeder must NEVER write to stdout.
#
# Commit 601c1f5 removed the `> /var/log/seed.log 2>&1` redirect so seed
# output would appear in Render's Logs tab. That broke the deploy with
# "Port scan timeout reached, no open ports detected": the reference-data
# seeder emits a lot of text, and pushing it through Render's log pipeline
# keeps the single vCPU busy long enough that the server's own startup
# misses Render's port-scan window.
#
# This is the same failure the redirect was originally added to fix
# (16af3ef "unblock Render deploy by starting server before seeding"), so
# the redirect is load-bearing, not cosmetic. Log visibility is not worth a
# failed deploy — to read seed output, re-add it temporarily and redeploy.
#
# `exec` makes the server replace this shell, so it becomes PID 1 and gets
# Render's SIGTERM directly instead of the shell swallowing it.
#
# NOTE on CACHE_STORE: not set here on purpose. An exported var would be baked
# into `config:cache` and would then WIN over the Render dashboard (verified:
# a cached value cannot be overridden by a later env var), which would make the
# cache store unchangeable without a redeploy. The safe default lives in
# config/cache.php instead — see the note there for why 'file' replaced
# Laravel's 'database' default on this remote-DB deployment.
#
# WHY route:cache + view:cache:
#   171 routes are re-registered and every Blade template is re-resolved on
#   each request otherwise. Both caches are built once here, at container
#   start, and are safe because the code is immutable inside a running
#   container. (Verified: `route:cache` succeeds on this route table — there
#   are no closure routes — and `view:cache` compiles cleanly.)
#
# WHY PHP_CLI_SERVER_WORKERS + --no-reload:
#   `artisan serve` is PHP's built-in server, which handles ONE request at a
#   time by default. Most of a request here is spent WAITING on the shared
#   remote MySQL, not on CPU, so a handful of workers lets those waits overlap
#   instead of queueing. POSIX only (the container is Linux); it is a no-op
#   anywhere else.
#
#   ⚠️ `--no-reload` IS REQUIRED, not cosmetic. Laravel's ServeCommand refuses
#   to apply PHP_CLI_SERVER_WORKERS without it and silently falls back to a
#   single server ("Unable to respect the `PHP_CLI_SERVER_WORKERS` environment
#   variable without the `--no-reload` flag"). Measured: without it,
#   concurrency did not improve at all. Reload-on-change is meaningless in a
#   container anyway — the code cannot change while it runs.
#
#   Replacing this with php-fpm + nginx remains the real fix.
CMD php artisan migrate --force \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && (php artisan db:seed --force > /var/log/seed.log 2>&1 &) \
    && export PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-4} \
    && exec php artisan serve --host=0.0.0.0 --port=${PORT:-10000} --no-reload