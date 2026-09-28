#!/bin/sh
# Starts nginx, PHP-FPM, Reverb and the scheduler under supervisord.
set -e

# PID 1 ignores signals without a handler; stop promptly even during startup.
trap 'exit 143' TERM INT QUIT

# Named volumes start empty, recreate the storage skeleton.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

# Secrets that are not configured are generated once and kept in the storage volume,
# so they survive restarts and image updates (a new APP_KEY would log everyone out).
# Values set in the environment always win.
secrets=storage/secrets.env

ensure_secret() {
    name=$1
    eval "value=\${$name:-}"
    if [ -z "$value" ] && [ -f "$secrets" ]; then
        value=$(sed -n "s/^$name='\(.*\)'\$/\1/p" "$secrets" | tail -n 1)
    fi
    if [ -z "$value" ]; then
        value=$2
        echo "$name='$value'" >> "$secrets"
        chmod 600 "$secrets"
        echo "Generated $name (stored in $secrets)"
    fi
    export "$name=$value"
}

random() {
    head -c "$1" /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"
}

ensure_secret APP_KEY "base64:$(head -c 32 /dev/urandom | base64)"
ensure_secret REVERB_APP_ID mdm
ensure_secret REVERB_APP_KEY "$(random 20)"
ensure_secret REVERB_APP_SECRET "$(random 32)"

if [ $# -gt 0 ]; then
    exec "$@"
fi

php artisan optimize

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    # The database container may still be starting.
    attempt=1
    until php artisan migrate --force; do
        if [ "$attempt" -ge "${MIGRATION_RETRIES:-30}" ]; then
            echo "Migrations failed, giving up" >&2
            exit 1
        fi
        echo "Database not ready, retrying in 2 s ($attempt)..." >&2
        attempt=$((attempt + 1))
        sleep 2
    done
fi

exec supervisord -c /etc/supervisord.conf
