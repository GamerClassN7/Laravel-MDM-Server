#!/bin/sh
# Starts nginx, PHP-FPM, Reverb and the scheduler under supervisord.
set -e

# PID 1 ignores signals without a handler; stop promptly even during startup.
trap 'exit 143' TERM INT QUIT

# Named volumes start empty, recreate the storage skeleton.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

# A volume mounted over database/ (to keep database.sqlite there) still holds the migrations of
# the image it was created from, so new migrations would never run. Always use this image's ones.
for dir in migrations seeders factories; do
    if ! diff -rq "/usr/share/laravel-mdm-database/$dir" "database/$dir" >/dev/null 2>&1; then
        if rm -rf "database/$dir" && cp -r "/usr/share/laravel-mdm-database/$dir" "database/$dir"; then
            echo "Updated database/$dir from the image"
        else
            echo "Warning: could not update database/$dir, new migrations may not run" >&2
        fi
    fi
done

# SQLite without an explicit path would live in the image (database/database.sqlite): not writable
# by the app user and lost with every image update. Keep it in the storage volume instead, unless
# a database file was mounted there.
if [ "${DB_CONNECTION:-}" = "sqlite" ] && [ -z "${DB_DATABASE:-}" ] && [ ! -e database/database.sqlite ]; then
    export DB_DATABASE=/var/www/storage/database.sqlite
fi
if [ "${DB_CONNECTION:-}" = "sqlite" ] && [ -n "${DB_DATABASE:-}" ] && [ ! -e "$DB_DATABASE" ]; then
    touch "$DB_DATABASE"
    echo "Created SQLite database $DB_DATABASE"
fi

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

# Reverb runs in this container: broadcast to it directly unless configured otherwise. Agents are
# told to connect to the address they reach the server on (see /api/device/realtime), so no
# REVERB_* settings are needed behind a reverse proxy.
if [ "${REVERB_ENABLED:-true}" = "true" ]; then
    : "${BROADCAST_CONNECTION:=reverb}"
    if [ -z "${REVERB_HOST:-}" ]; then
        REVERB_HOST=127.0.0.1 REVERB_PORT=8080 REVERB_SCHEME=http
    fi
    export REVERB_HOST REVERB_PORT REVERB_SCHEME
else
    : "${BROADCAST_CONNECTION:=null}"
fi
export BROADCAST_CONNECTION

# Errors always reach the container output (docker logs), also when .env logs to a file
# (LOG_CHANNEL=daily from .env.example): that channel is kept and stderr is added next to it.
case "${LOG_CHANNEL:=stderr}" in
    stderr) ;;
    stack)
        case ",${LOG_STACK:=single}," in
            *,stderr,*) ;;
            *) LOG_STACK="$LOG_STACK,stderr" ;;
        esac
        ;;
    *)
        LOG_STACK="$LOG_CHANNEL,stderr"
        LOG_CHANNEL=stack
        ;;
esac
export LOG_CHANNEL LOG_STACK

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
