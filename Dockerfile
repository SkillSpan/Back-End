FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql mbstring zip gd opcache \
    && rm -rf /var/lib/apt/lists/*

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=24'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/zz-opcache.ini

RUN { \
        echo 'upload_max_filesize=12M'; \
        echo 'post_max_size=14M'; \
        echo 'memory_limit=256M'; \
        echo 'max_input_time=60'; \
    } > /usr/local/etc/php/conf.d/zz-upload.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --optimize-autoloader --no-scripts --no-interaction

COPY . .

RUN composer dump-autoload --optimize --no-scripts \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 10000

CMD php artisan migrate --force \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && (php artisan db:seed --force > /var/log/seed.log 2>&1 &) \
    && export PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-4} \
    && exec php artisan serve --host=0.0.0.0 --port=${PORT:-10000} --no-reload