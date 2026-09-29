# Runtime image preparation is a separate environment-change operation.
# Do not build this Dockerfile during ordinary start or application update.
FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" mbstring pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/peanut-admin/server
CMD ["php-fpm", "-F"]
