FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpng-dev libonig-dev libxml2-dev libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql mbstring exif pcntl bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy composer files first for layer caching
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy all application files
COPY . .

# Create a dummy .env so artisan can boot during build (real values injected at runtime)
RUN cp .env.example .env && \
    php artisan key:generate --force && \
    chmod -R 775 storage bootstrap/cache && \
    chmod +x start.sh

EXPOSE 8000

CMD ["/bin/bash", "/var/www/start.sh"]
