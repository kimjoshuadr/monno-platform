#!/bin/sh

cd /app/backend

if ! php artisan migrate --force; then
    echo "============================================"
    echo "ERROR: Migrations could not complete. Check the error above."
    echo "Ensure DATABASE_URL is set."
    echo "Aborting startup to avoid running a half-migrated application."
    echo "============================================"
    exit 1
fi

php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan storage:link

# One-off seed. Destructive (wipes events/organizers), so it only runs when the
# deploy explicitly asks for it, via MONNO_SEED_ON_START=true.
if [ "${MONNO_SEED_ON_START:-}" = "true" ]; then
    echo "MONNO_SEED_ON_START=true — seeding the curated monno content …"
    php artisan monno:seed --force || echo "WARNING: monno:seed failed (check the output above)"
fi

chown -R www-data:www-data /app/backend
chmod -R 775 /app/backend/storage /app/backend/bootstrap/cache

exec /usr/bin/supervisord -c /etc/supervisord.conf
