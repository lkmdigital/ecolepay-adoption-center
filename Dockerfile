# syntax=docker/dockerfile:1
# =============================================================================
#  EcolePay Adoption Center — image de production
#  Pensée pour un déploiement par IMPORT DE DÉPÔT GIT (Railway, Render, Coolify,
#  Dokploy, Fly, DigitalOcean…). PHP 8.4 imposé (une dépendance bloque en 8.5).
#  Base de données requise : MySQL/MariaDB (l'app utilise du SQL spécifique MySQL).
# =============================================================================

# ── Étape 1 : compilation des assets front (Vite) ────────────────────────────
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ── Étape 2 : image applicative (PHP 8.4 + nginx) ────────────────────────────
FROM php:8.4-fpm-bookworm

# Extensions PHP (Laravel + exports Excel + phone hash…).
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions pdo_mysql mbstring gd zip intl bcmath exif pcntl opcache

# nginx + envsubst (gettext) + composer.
RUN apt-get update \
 && apt-get install -y --no-install-recommends nginx gettext-base \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /etc/nginx/sites-enabled/default
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dépendances PHP — d'abord les manifestes pour profiter du cache de couches.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Code applicatif + assets compilés (depuis l'étape 1).
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --no-dev --optimize --no-scripts \
 && mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

# Conf nginx (modèle) + entrypoint.
COPY docker/nginx-default.conf.template /etc/nginx/conf.d/default.conf.template
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

ENV PORT=8080
EXPOSE 8080
CMD ["/usr/local/bin/start.sh"]
