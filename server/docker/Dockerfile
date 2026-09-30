# Runtime image preparation is a separate environment-change operation.
# Do not build this Dockerfile during ordinary start or application update.
FROM composer:2.10.2@sha256:d020706319701a44468968321dccd0fce6620190159a7a9ec195d78e6e971c71 AS composer_binary

FROM php:8.3-fpm-bookworm@sha256:536a1188a926efe7e6f927b7ca01f3a043c63d0a527567c6d4d3bf9fbd637866

# Composer is part of the prepared PHP runtime, not fetched during a request.
COPY --from=composer_binary /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates git libcurl4-openssl-dev libonig-dev libxml2-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" curl dom mbstring pdo_mysql simplexml zip \
    && printf '%s  %s\n' '5ee7125f8a30a34d246cefdc0bc85b8a783b28f2aec968994118512350d28027' /usr/local/bin/composer | sha256sum -c - \
    && php -r 'foreach (["curl","dom","mbstring","pdo_mysql","simplexml","sodium","zip"] as $extension) { if (!extension_loaded($extension)) {fwrite(STDERR, "missing PHP extension: $extension\n"); exit(1); }}' \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/peanut-admin/server
CMD ["php-fpm", "-F"]
