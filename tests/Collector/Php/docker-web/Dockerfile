ARG PHP_VERSION
FROM php:${PHP_VERSION}-fpm

RUN apt-get update -qq \
    && apt-get install -y --no-install-recommends nginx netcat-openbsd unzip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-enable opcache || echo "OPcache already compiled in, skipping"
RUN pecl install apcu && docker-php-ext-enable apcu || echo "APCu install skipped for this PHP version"

COPY nginx.conf /etc/nginx/conf.d/default.conf
RUN rm -f /etc/nginx/sites-enabled/default
COPY php-web.ini /usr/local/etc/php/conf.d/jmonitor-web.ini
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /app
EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
