#!/bin/sh
set -eu

if [ ! -f "/var/www/html/modules/extsentry/vendor/autoload.php" ]; then
    echo "\n* Installing module composer dependencies\n"

    runuser -u www-data -- composer install \
        --working-dir="/var/www/html/modules/extsentry" \
        --no-interaction \
        --no-progress \
        --prefer-dist
fi

exec /tmp/docker_run.sh
