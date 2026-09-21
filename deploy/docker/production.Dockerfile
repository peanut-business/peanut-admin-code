# syntax=docker/dockerfile:1.7

FROM node:22.22.0-bookworm-slim AS client-base

COPY packages/core-web/peanut-admin-client-4.0.0-dev.0.tgz \
    packages/core-web/peanut-admin-nuxt-4.0.0-dev.0.tgz \
    packages/core-web/peanut-admin-testing-4.0.0-dev.0.tgz \
    packages/core-web/peanut-admin-ui-vue-4.0.0-dev.0.tgz \
    packages/core-web/peanut-admin-uniapp-4.0.0-dev.0.tgz \
    packages/core-web/peanut-admin-vue-4.0.0-dev.0.tgz \
    /build/packages/core-web/

FROM client-base AS admin-builder

WORKDIR /build/web
RUN corepack enable && corepack prepare pnpm@10.15.0 --activate
COPY web/package.json web/pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
COPY plugins.lock /build/plugins.lock
COPY scripts/client-environment.ts /build/scripts/client-environment.ts
COPY web/ ./
RUN printf '%s\n' 'VITE_DEPLOYMENT_MODE=standalone' 'VITE_API_BASE_URL=' > .env.standalone \
    && printf '%s\n' 'VITE_DEPLOYMENT_MODE=multi-tenant' 'VITE_API_BASE_URL=' > .env.multi-tenant \
    && pnpm exec vue-tsc --noEmit \
    && PEANUT_CLIENT_ENV_FILE=/build/web/.env.standalone pnpm exec vite build --config ./config/vite.config.prod.ts --outDir dist/standalone \
    && PEANUT_CLIENT_ENV_FILE=/build/web/.env.multi-tenant pnpm exec vite build --config ./config/vite.config.prod.ts --outDir dist/multi-tenant

FROM client-base AS mobile-builder

WORKDIR /build/uniapp
COPY uniapp/package.json uniapp/package-lock.json ./
RUN npm ci --legacy-peer-deps
COPY scripts/client-environment.ts /build/scripts/client-environment.ts
COPY uniapp/ ./
RUN npm run build:h5

FROM client-base AS platform-builder

WORKDIR /build/platform
COPY platform/package.json platform/package-lock.json ./
RUN npm ci
COPY scripts/client-environment.ts /build/scripts/client-environment.ts
COPY platform/ ./
RUN npm run build

FROM client-base AS pc-builder

WORKDIR /build/pc
COPY pc/package.json pc/package-lock.json ./
RUN npm ci
COPY scripts/client-environment.ts /build/scripts/client-environment.ts
COPY pc/ ./
RUN npm run generate

FROM composer:2.8 AS composer-deps

WORKDIR /build/server
COPY server/composer.json server/composer.lock ./
COPY server/app app
COPY server/database/schema database/schema
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts

FROM php:8.3-fpm-bookworm AS php

ARG PEANUT_DEPLOYMENT_RECEIPT_BASE64=""

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" curl mbstring pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/peanut-admin

COPY deploy/docker/php-upload.ini /usr/local/etc/php/conf.d/peanut-upload.ini

COPY release-versions.json release-versions.json
COPY RELEASE_METADATA.json RELEASE_METADATA.json
COPY LICENSE NOTICE THIRD_PARTY_NOTICES.md RELEASE_SBOM.spdx.json CHANGELOG.md RELEASE_METADATA.json legal/
COPY resources/project-resources.json resources/project-resources.json
COPY plugins.lock plugins.lock
COPY plugins plugins
COPY web/src/modules web/src/modules
COPY platform/src/modules platform/src/modules
COPY server/app server/app
COPY server/bootstrap server/bootstrap
COPY server/config server/config
COPY server/database server/database
COPY server/extend server/extend
COPY server/route server/route
COPY server/view server/view
COPY server/think server/think
COPY server/composer.json server/composer.lock server/
COPY server/public server/public
COPY server/resources/schemas server/resources/schemas
COPY --from=composer-deps /build/server/vendor server/vendor
COPY deploy/docker/php-entrypoint.sh /usr/local/bin/peanut-php-entrypoint

RUN if [ -n "$PEANUT_DEPLOYMENT_RECEIPT_BASE64" ]; then \
        printf '%s' "$PEANUT_DEPLOYMENT_RECEIPT_BASE64" | base64 --decode > DEPLOYMENT_RECEIPT.json; \
        chmod 0444 DEPLOYMENT_RECEIPT.json; \
    fi \
    && mkdir -p server/runtime server/public/storage server/private/storage \
    && cd server \
    && printf '%s\n' \
        'APP_ENV=production' \
        'APP_DEBUG=false' \
        'PEANUT_DATABASE_RESOURCE_ID=peanut-admin-build-only' \
        'DEPLOYMENT_MODE=standalone' \
        'DB_HOST=build-only.invalid' \
        'DB_PORT=3306' \
        'DB_NAME=build_only' \
        'DB_USER=build_only' \
        'DB_PASS=build-only' \
        'DB_PREFIX=pa_' > .env.image-build \
    && chmod 600 .env.image-build \
    && PEANUT_SERVER_ENV_FILE=/var/www/peanut-admin/server/.env.image-build php think service:discover \
    && PEANUT_SERVER_ENV_FILE=/var/www/peanut-admin/server/.env.image-build php think vendor:publish \
    && rm -f .env.image-build \
    && cd .. \
    && chmod +x server/think server/database/seed-demo-data.php /usr/local/bin/peanut-php-entrypoint \
    && chmod +x server/database/seed-multi-tenant-demo.php \
    && ln -s /var/www/peanut-admin/server/database/seed-demo-data.php /usr/local/bin/peanut-seed-demo-data \
    && ln -s /var/www/peanut-admin/server/database/seed-multi-tenant-demo.php /usr/local/bin/peanut-seed-multi-tenant-demo \
    && chown -R www-data:www-data server/runtime server/public/storage server/private/storage

EXPOSE 9000

FROM nginx:1.28.0-alpine AS nginx

COPY deploy/nginx/peanut-admin.conf /etc/nginx/conf.d/default.conf
COPY deploy/docker/nginx-select-admin.sh /docker-entrypoint.d/40-select-admin.sh
COPY server/public /var/www/peanut-admin/server/public
COPY LICENSE NOTICE THIRD_PARTY_NOTICES.md RELEASE_SBOM.spdx.json CHANGELOG.md RELEASE_METADATA.json /var/www/peanut-admin/server/public/legal/
COPY --from=admin-builder /build/web/dist /opt/peanut-admin/admin
COPY --from=platform-builder /build/platform/dist /var/www/peanut-admin/server/public/platform
COPY --from=mobile-builder /build/uniapp/dist/build/h5 /var/www/peanut-admin/server/public/mobile
COPY --from=pc-builder /build/pc/.output/public /var/www/peanut-admin/server/public/pc

RUN chmod +x /docker-entrypoint.d/40-select-admin.sh \
    && mkdir -p /var/www/peanut-admin/server/public/storage
