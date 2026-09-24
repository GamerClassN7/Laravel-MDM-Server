#!/bin/sh
# CONTAINER_ROLE: app (nginx + php-fpm on :8000), reverb (WebSocket server on :8080), scheduler
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

case "$CONTAINER_ROLE" in
    app)
        if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
            php artisan migrate --force
        fi

        php-fpm84 --nodaemonize &
        fpm=$!
        nginx -e stderr -g 'daemon off;' &
        web=$!

        stopping=0
        trap 'stopping=1; kill -TERM $fpm $web 2>/dev/null' TERM INT QUIT
        # Stop the container when either process exits.
        while kill -0 $fpm 2>/dev/null && kill -0 $web 2>/dev/null; do
            sleep 2
        done
        kill -TERM $fpm $web 2>/dev/null || true
        wait
        # A process that died on its own is a failure, so the restart policy kicks in.
        [ "$stopping" = 1 ] || exit 1
        ;;
    reverb)
        exec php artisan reverb:start --host=0.0.0.0 --port="${REVERB_SERVER_PORT:-8080}"
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    *)
        echo "Unknown CONTAINER_ROLE: $CONTAINER_ROLE" >&2
        exit 1
        ;;
esac
