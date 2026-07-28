#!/usr/bin/env bash
# Entrypoint du conteneur EAC : prépare Laravel puis sert via nginx + php-fpm.
set -e

export PORT="${PORT:-8080}"

# Conf nginx avec le port fourni par la plateforme (Railway/Render/Coolify…).
envsubst '${PORT}' < /etc/nginx/conf.d/default.conf.template > /etc/nginx/conf.d/default.conf

# Dossiers d'exécution + droits.
mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Laravel : découverte des paquets, migrations (idempotentes), caches de prod.
php artisan package:discover --ansi || true
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link || true

# php-fpm en arrière-plan, nginx au premier plan (PID 1 du conteneur).
php-fpm -D
exec nginx -g 'daemon off;'
