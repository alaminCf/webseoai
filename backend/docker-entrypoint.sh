#!/bin/sh
set -e

PORT="${PORT:-80}"

# Default APP_KEY if not injected via Vercel env vars
if [ -z "${APP_KEY:-}" ]; then
    export APP_KEY="base64:L+i8jagaIVLob7C6PXuC3aZQBaK5FTQYWLxfFPGbITc="
fi

# Configure database: prefer Neon/PostgreSQL when DATABASE_URL or POSTGRES_URL is configured
if [ -n "${DATABASE_URL:-}" ] || [ -n "${POSTGRES_URL:-}" ]; then
    echo "[entrypoint] Configuring Neon PostgreSQL database..." >&2
    export DB_CONNECTION="pgsql"
    export DB_URL="${DATABASE_URL:-$POSTGRES_URL}"
    MIGRATE_URL="${DATABASE_URL_UNPOOLED:-${POSTGRES_URL_NON_POOLING:-$DB_URL}}"

    DB_URL="$MIGRATE_URL" php artisan migrate --force || echo "[entrypoint] Warning: migrate returned non-zero" >&2

    # Seed initial demo workspace only if users table is empty
    USER_COUNT=$(php -r "require '/app/vendor/autoload.php'; \$app = require '/app/bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo \App\Models\User::count();" 2>/dev/null || echo "1")
    if [ "$USER_COUNT" = "0" ]; then
        echo "[entrypoint] Seeding initial workspace into PostgreSQL..." >&2
        php artisan db:seed --force || echo "[entrypoint] Warning: seed returned non-zero" >&2
    fi
else
    echo "[entrypoint] Using ephemeral SQLite fallback (/tmp/database.sqlite)..." >&2
    export DB_CONNECTION="sqlite"
    export DB_DATABASE="/tmp/database.sqlite"
    if [ ! -f "/tmp/database.sqlite" ]; then
        cp /app/database/seed-template.sqlite /tmp/database.sqlite
    fi
fi

# Keep cache & views writable in /tmp for serverless containers
mkdir -p /tmp/laravel/views /tmp/laravel/cache
export VIEW_COMPILED_PATH="/tmp/laravel/views"

# Start FrankenPHP serving Laravel's public/ directory on $PORT
exec frankenphp php-server \
    --listen ":${PORT}" \
    --root /app/public
