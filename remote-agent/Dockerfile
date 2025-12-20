FROM php:8.3-cli-alpine

RUN apk add --no-cache fping curl bash \
    && apk add --no-cache --virtual .build-deps git \
    && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"

WORKDIR /app

# Copy skeleton agent
COPY . /app

RUN if [ -f composer.json ]; then composer install --no-dev --optimize-autoloader; fi

CMD ["php", "/app/agent.php"]
