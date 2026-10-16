# syntax=docker/dockerfile:1

# —— Base commune (développement et future image de production, cf. #82) ——
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
