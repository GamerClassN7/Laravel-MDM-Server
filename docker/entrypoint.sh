#!/bin/sh
# Starts nginx, PHP-FPM, Reverb and the scheduler under supervisord.
set -e

# PID 1 ignores signals without a handler; stop promptly even during startup.
trap 'exit 143' TERM INT QUIT

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set, generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# Named volumes start empty, recreate the storage skeleton.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

if [ $# -gt 0 ]; then
    exec "$@"
fi

php artisan optimize

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

exec supervisord -c /etc/supervisord.conf
