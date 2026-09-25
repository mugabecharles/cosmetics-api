#!/bin/bash
set -e

# Install dependencies
composer install --no-dev --optimize-autoloader

# Generate app key if not set
php artisan key:generate --force

# Run migrations and seed
php artisan migrate --force
php artisan db:seed --force

# Cache
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✅ Build complete"
