FROM php:8.4.15-fpm-alpine

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
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
    bind-tools

# Install PHP extensions
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

# Create simple healthcheck script
RUN echo '#!/bin/sh' > /usr/local/bin/php-fpm-healthcheck \
    && echo 'SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000' >> /usr/local/bin/php-fpm-healthcheck \
    && chmod +x /usr/local/bin/php-fpm-healthcheck

# Install fcgi for healthcheck
RUN apk add --no-cache fcgi

# Configure PHP-FPM
RUN echo "pm = dynamic" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo "pm.max_children = 50" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo "pm.start_servers = 10" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo "pm.min_spare_servers = 5" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo "pm.max_spare_servers = 20" >> /usr/local/etc/php-fpm.d/zz-docker.conf

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Switch to www-data user
USER www-data

# Expose port 9000 for PHP-FPM
EXPOSE 9000

CMD ["php-fpm"]
