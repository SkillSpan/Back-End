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
CMD php artisan migrate --force \
    && php artisan config:cache \
    && (php artisan db:seed --force > /var/log/seed.log 2>&1 &) \
    && php artisan serve --host=0.0.0.0 --port=${PORT:-10000}