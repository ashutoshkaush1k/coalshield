# Development image for the Yii2 API (docker-compose.yml). Not verified yet - see PROGRESS.md.
FROM php:8.2-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libicu-dev unzip git \
 && docker-php-ext-install pdo_pgsql pgsql intl \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
EXPOSE 8080

# Code is mounted at /app; vendor lives in a volume. Install, migrate, then serve.
CMD ["sh", "-c", "composer install --no-interaction --prefer-dist && php yii migrate --interactive=0 && php yii rbac/init && php -S 0.0.0.0:8080 -t web web/index.php"]
