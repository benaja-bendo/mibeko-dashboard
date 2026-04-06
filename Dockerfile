# ─────────────────────────────────────────────────────────────
# Stage 1 : Installation des dépendances PHP (Composer)
# ─────────────────────────────────────────────────────────────
FROM composer:2 AS composer-builder

WORKDIR /app

# Copier composer.json/lock en premier (layer cache)
COPY composer.json composer.lock ./

# Installer les deps PHP sans les deps dev, avec optimisations autoloader
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

# Copier le reste des sources
COPY . .

# Re-run des scripts post-install (sans scripts nécessitant DB)
RUN composer run-script post-autoload-dump 2>/dev/null || true

# ─────────────────────────────────────────────────────────────
# Stage 2 : Build des assets front-end (Vite/React/Inertia)
# ─────────────────────────────────────────────────────────────
FROM node:22-alpine AS node-builder

# Installer PHP car requis par @laravel/vite-plugin-wayfinder pendant le build
RUN apk add --no-cache php php-phar php-mbstring php-openssl php-json php-tokenizer php-dom php-xml php-xmlwriter php-session php-pdo php-fileinfo php-iconv php-simplexml php-zip php-curl php-sockets

WORKDIR /app

# Copier les dépendances node
COPY package.json ./
RUN npm install

# Copier tout le projet (incluant artisan)
COPY . .

# Récupérer vendor depuis le stage composer pour que wayfinder puisse s'exécuter
COPY --from=composer-builder /app/vendor ./vendor

# Variables Vite injectées au build time (overridable via --build-arg dans la CI)
ARG VITE_REVERB_APP_KEY=shg62qdm61dsnvyzmzpy
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=http
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
ENV VITE_REVERB_HOST=${VITE_REVERB_HOST}
ENV VITE_REVERB_PORT=${VITE_REVERB_PORT}
ENV VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

# Build des assets
RUN cp .env.example .env \
    && php artisan key:generate \
    && php artisan wayfinder:generate --with-form \
    && npm run build

# ─────────────────────────────────────────────────────────────
# Stage 3 : Image de production PHP-FPM
# ─────────────────────────────────────────────────────────────
FROM php:8.4-fpm-alpine AS production

# Extensions système nécessaires
RUN apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libzip-dev \
    icu-dev \
    postgresql-dev \
    oniguruma-dev \
    freetype-dev \
    linux-headers \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        bcmath \
        exif \
        pcntl \
        intl \
        sockets \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/*

# Configuration OPcache et Uploads pour la production
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/php/zz-fpm.conf /usr/local/etc/php-fpm.d/zz-fpm.conf

WORKDIR /var/www/html

# Copier les dépendances PHP depuis le stage composer
COPY --from=composer-builder --chown=www-data:www-data /app/vendor ./vendor

# Copier le code source
COPY --chown=www-data:www-data . .

# Copier les assets compilés depuis le stage node
COPY --from=node-builder --chown=www-data:www-data /app/public/build ./public/build

# Permissions storage et cache
RUN mkdir -p storage/logs storage/framework/sessions storage/framework/views storage/framework/cache/data \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
