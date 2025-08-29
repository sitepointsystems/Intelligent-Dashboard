FROM php:8.3-apache

# Enable useful Apache modules
RUN a2enmod rewrite headers

# Copy code
COPY . /var/www/html

# PHP tuning
COPY .docker/php.ini /usr/local/etc/php/conf.d/zz-custom.ini

# Create a persistent data dir for EasyPanel to mount
RUN mkdir -p /data && chown -R www-data:www-data /data /var/www/html

# Environment variables used by your code
# - ENV_PATH: where auth/proxy read/write the .env
# - PROP_FILE: where index.php stores GA properties JSON
ENV ENV_PATH=/data/.env \
    PROP_FILE=/data/ga_properties.json

EXPOSE 80
