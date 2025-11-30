FROM php:8.4-cli-alpine

# Install fping and networking tools
RUN apk add --no-cache fping iputils

# Setup working directory
WORKDIR /app

# Copy the scanner script (default location, can be overridden by volume)
COPY remote_scanner.php /app/remote_scanner.php

CMD ["php", "/app/remote_scanner.php"]
