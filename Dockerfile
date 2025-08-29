# PHP + Apache 2
FROM php:8.2-apache

# System deps & PHP extensions
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev git unzip ca-certificates curl \
 && docker-php-ext-install zip \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

# App code
WORKDIR /var/www/html
COPY . /var/www/html

# Ownership
RUN chown -R www-data:www-data /var/www/html

# Optional php.ini
COPY .docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# Persist .env (and other runtime files) outside the image
ENV AUTH_ENV_PATH=/data/.env
VOLUME ["/data"]

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD curl -fsS http://localhost/ || exit 1
