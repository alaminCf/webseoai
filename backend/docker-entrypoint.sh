#!/bin/sh
set -e

PORT="${PORT:-80}"

# Default APP_KEY if not injected via Vercel env vars
if [ -z "${APP_KEY:-}" ]; then
    export APP_KEY="base64:L+i8jagaIVLob7C6PXuC3aZQBaK5FTQYWLxfFPGbITc="
fi

# Configure database: prefer PostgreSQL if DATABASE_URL / POSTGRES_URL is set, otherwise writable /tmp SQLite
if [ -n "${DATABASE_URL:-}" ] || [ -n "${POSTGRES_URL:-}" ]; then
    export DB_CONNECTION="pgsql"
    export DB_URL="${DATABASE_URL:-$POSTGRES_URL}"
    php artisan migrate --force || true
    # Seed initial demo workspace only if users table is empty
    USER_COUNT=$(php -r "require '/app/vendor/autoload.php'; \$app = require '/app/bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo \App\Models\User::count();" 2>/dev/null || echo "1")
    if [ "$USER_COUNT" = "0" ]; then
        php artisan db:seed --force || true
    fi
else
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
