# syntax=docker/dockerfile:1

# —— Base commune (développement et production) ——
FROM php:8.5-fpm-alpine AS base

WORKDIR /app

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions apcu intl opcache pdo_pgsql redis zip

COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/zz-app.ini

# —— Développement : sources montées en volume, Xdebug désactivé par défaut ——
FROM base AS dev

ENV XDEBUG_MODE=off \
    HOME=/tmp \
    COMPOSER_HOME=/tmp/composer

RUN install-php-extensions xdebug \
    && mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Navigateur des tests de bout en bout (Panther, make test-e2e) ; versions suivies par la branche Alpine de l'image
# hadolint ignore=DL3018
RUN apk add --no-cache chromium chromium-chromedriver

ENV PANTHER_NO_SANDBOX=1 \
    PANTHER_CHROME_BINARY=/usr/bin/chromium-browser

COPY docker/php/conf.d/app.dev.ini $PHP_INI_DIR/conf.d/zz-app.dev.ini

# —— Production : code et dépendances dans l'image, sans outils de développement (docs/exploitation.md) ——
FROM base AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_HOME=/tmp/composer

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/app.prod.ini $PHP_INI_DIR/conf.d/zz-app.prod.ini

# Dépendances d'abord : couche réutilisée tant que composer.lock ne change pas
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY . .

# Autoloader optimisé, .env figé en .env.local.php (les variables d'environnement réelles priment), assets compilés
# et cache chauffé : rien ne s'écrit dans le code au démarrage
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && bin/console importmap:install \
    && bin/console asset-map:compile \
    && bin/console cache:warmup \
    && chown -R www-data:www-data var

USER www-data

# —— Serveur web de production : nginx avec les fichiers publics compilés de l'image PHP ——
FROM nginx:1.31-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /app/public /app/public
