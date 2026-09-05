FROM php:8.5-fpm-bookworm

# The base image already enables Laravel/PHPUnit's mbstring, curl and XML extensions.
# Only the PostgreSQL PDO driver is missing; Composer extracts archives with unzip.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ARG LOCAL_UID=1000
ARG LOCAL_GID=1000
RUN groupadd -g "${LOCAL_GID}" app && useradd -m -u "${LOCAL_UID}" -g app app
COPY docker/php/entrypoint.sh /usr/local/bin/keiba-entrypoint
RUN chmod +x /usr/local/bin/keiba-entrypoint
WORKDIR /var/www/html
USER app
ENTRYPOINT ["keiba-entrypoint"]
CMD ["php-fpm", "-F"]
