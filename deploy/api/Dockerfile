# The API for the online version (Render, free web service - docs/DEPLOYMENT.md).
# Build context: the repository root (render.yaml: dockerContext "."), because the API reads
# data/schema, data/reference and ai-service/risk/model.json at run time.
# Every setting comes from environment variables set in Render; nothing secret is in the image.
# The laptop does not use this file (it runs XAMPP's Apache; api/Dockerfile is the old dev image).
FROM php:8.2-apache-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libicu-dev unzip \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl opcache \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app/api

# Dependencies first (cached while composer.lock is unchanged); no test tools.
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY api/ ./
COPY data/schema/ /var/www/app/data/schema/
COPY data/reference/ /var/www/app/data/reference/
COPY ai-service/risk/model.json /var/www/app/ai-service/risk/model.json

RUN composer dump-autoload --no-dev --optimize \
 && rm -rf .env tests runtime/* storage/files \
 && mkdir -p runtime storage/files \
 && chown -R www-data:www-data runtime storage

COPY deploy/api/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/api/php.ini /usr/local/etc/php/conf.d/zz-coalshield.ini
RUN a2enmod headers \
 && sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
 && printf '<IfModule mpm_prefork_module>\n  StartServers 2\n  MinSpareServers 2\n  MaxSpareServers 4\n  MaxRequestWorkers 8\n  MaxConnectionsPerChild 500\n</IfModule>\n' > /etc/apache2/conf-enabled/zz-workers.conf

# Render sets PORT (10000 by default); production defaults for everything that is not secret.
ENV PORT=10000 \
    YII_DEBUG=0 \
    YII_ENV=prod \
    LOG_STDERR=1 \
    DB_PERSISTENT=0 \
    DB_SSLMODE=require \
    AI_ENGINE=php \
    VISION_AVAILABLE=0 \
    STORAGE_DRIVER=supabase \
    API_DEBUG_ERRORS=0

EXPOSE 10000
