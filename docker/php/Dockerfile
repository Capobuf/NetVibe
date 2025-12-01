FROM dunglas/frankenphp:php8.4-alpine

# Set working directory
WORKDIR /var/www/html

# Install system dependencies (tools used by NetVibe)
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    autoconf \
    g++ \
    make \
    linux-headers \
    fping \
    iputils \
    bind-tools \
    fcgi

# Configure and install PHP extensions we rely on. FrankenPHP images provide the
# standard docker-php-* helper scripts, so we reuse them here.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mysqli \
    mbstring \
    xml \
    bcmath \
    gd \
    zip \
    intl \
    opcache \
    pcntl \
    sockets

# Install Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Copy application source into the image so both app and worker containers have artisan available.
# Build context is repository root (docker-compose uses context: ..), so this copies the app into /var/www/html
COPY . /var/www/html

# Install PHP dependencies (prefer to keep dev deps out of production builds)
# Fail the build if dependencies can't be installed so we catch issues early.
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# Ensure Laravel Octane is installed and the config scaffold exists so the
# `php artisan octane:*` commands are available. Use --no-interaction to keep
# the build non-interactive.
RUN php artisan octane:install --server=frankenphp --no-interaction || true

# Create a lightweight healthcheck which probes the application HTTP port
RUN echo '#!/bin/sh' > /usr/local/bin/http-healthcheck \
    && echo 'wget --spider --quiet --tries=1 http://localhost/up || exit 1' >> /usr/local/bin/http-healthcheck \
    && chmod +x /usr/local/bin/http-healthcheck

# Ensure php-fpm config directory exists, then configure a small app-specific
# PHP-FPM config so FrankenPHP can use it
RUN mkdir -p /usr/local/etc/php-fpm.d \
    && chmod 755 /usr/local/etc/php-fpm.d \
    && echo "[www]" > /usr/local/etc/php-fpm.d/zz-app.conf \
    && echo "listen = 0.0.0.0:80" >> /usr/local/etc/php-fpm.d/zz-app.conf \
    && echo "pm = dynamic" >> /usr/local/etc/php-fpm.d/zz-app.conf \
    && echo "pm.max_children = 50" >> /usr/local/etc/php-fpm.d/zz-app.conf \
    && echo "pm.start_servers = 10" >> /usr/local/etc/php-fpm.d/zz-app.conf \
    && echo "pm.min_spare_servers = 5" >> /usr/local/etc/php-fpm.d/zz-app.conf \
    && echo "pm.max_spare_servers = 20" >> /usr/local/etc/php-fpm.d/zz-app.conf \
    && chown -R www-data:www-data /usr/local/etc/php-fpm.d

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Switch to non-root user for runtime (keep build steps as root)
USER www-data

# FrankenPHP serves HTTP directly on port 80 inside the container
EXPOSE 80

# Default command will start FrankenPHP; docker-compose may override the exact args
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=80", "--admin-port=2019"]
