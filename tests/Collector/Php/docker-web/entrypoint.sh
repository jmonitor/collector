#!/bin/sh
set -e

if [ ! -f /app/vendor/autoload.php ]; then
    echo "Installing composer dependencies..."
    php /tmp/composer.phar install --no-interaction --prefer-dist --quiet
fi

php-fpm -F &
until nc -z 127.0.0.1 9000; do sleep 0.1; done
exec nginx -g 'daemon off;'
