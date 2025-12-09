FROM serversideup/php:8.4-fpm-nginx

# ServerSideUp switches to www-data, switch back to root for installation
USER root

# Install additional PHP extensions needed by NetVibe
# Note: pcntl and redis are already installed in the base image
# - bcmath: Required for IP address calculations
# - gd: Image processing
# - intl: Internationalization
# - sockets: Network discovery features
RUN install-php-extensions bcmath gd intl sockets

# Install network tools needed for discovery features
RUN apt-get update && apt-get install -y --no-install-recommends \
    fping \
    iputils-ping \
    dnsutils \
    && rm -rf /var/lib/apt/lists/*

# Copy Composer files first for better layer caching
COPY --chown=www-data:www-data composer.json composer.lock /var/www/html/

# Install PHP dependencies as www-data
USER www-data
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts

# Switch back to root for copying source
USER root

# Copy application source
COPY --chown=www-data:www-data . /var/www/html

# Finalize as www-data
USER www-data

# Rebuild autoload with full source tree
RUN composer dump-autoload --optimize

# Laravel package discovery
RUN php artisan package:discover --ansi

# Return to root for S6 Overlay entrypoint
USER root

