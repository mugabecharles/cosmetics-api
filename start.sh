#!/bin/bash
set -e

echo "==> Writing .env file from environment variables..."

cat > /var/www/.env << EOF
APP_NAME="${APP_NAME:-Cosmetics Shop}"
APP_ENV=${APP_ENV:-production}
APP_KEY=${APP_KEY}
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=${APP_URL:-http://localhost}

DB_CONNECTION=${DB_CONNECTION:-pgsql}
DB_URL=${DATABASE_URL}

SESSION_DRIVER=${SESSION_DRIVER:-file}
CACHE_STORE=${CACHE_STORE:-file}
LOG_CHANNEL=${LOG_CHANNEL:-stderr}
LOG_LEVEL=${LOG_LEVEL:-error}

JWT_SECRET=${JWT_SECRET}
FRONTEND_URL=${FRONTEND_URL}
EOF

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Seeding database..."
php artisan db:seed --force

echo "==> Clearing config cache..."
php artisan config:clear

echo "==> Starting server on port ${PORT:-8000}..."
php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
