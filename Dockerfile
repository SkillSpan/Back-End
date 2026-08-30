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

# Render sets $PORT; run migrations, seed the reference lists (universities,
# specializations) so the frontend autocomplete dropdowns are populated, then
# start the server on that port.
CMD php artisan migrate --force \
    && php artisan db:seed --class=UniversitiesAndSpecializationsSeeder --force \
    && php artisan config:cache \
    && php artisan serve --host=0.0.0.0 --port=${PORT:-10000}
