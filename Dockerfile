FROM php:8.2-cli

# Install system dependencies and PHP extensions Laravel needs
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql mbstring zip gd \
    && rm -rf /var/lib/apt/lists/*

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
CMD php artisan migrate --force \
    && php artisan config:cache \
    && (php artisan db:seed --force > /var/log/seed.log 2>&1 &) \
    && exec php artisan serve --host=0.0.0.0 --port=${PORT:-10000}