#!/bin/sh
set -e

# Wait for MySQL and run migrations + seed only from the api service.
if [ "$TURNSTILE_ROLE" = "api" ]; then
  until php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; do
    echo "waiting for mysql..."; sleep 1
  done
  php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
  php bin/console turnstile:seed --if-empty || true
fi

exec "$@"
