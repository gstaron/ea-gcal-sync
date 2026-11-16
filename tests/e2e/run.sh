#!/usr/bin/env bash
# End-to-end test: WordPress (verzia ako produkcia) + MariaDB + ACF v Dockeri, Google API je simulované.
# Použitie: tests/e2e/run.sh [verzia-WP]   (predvolene 6.7.5)
set -euo pipefail

WP_VERSION="${1:-6.7.5}"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
NAME="eags-e2e-$$"

cleanup() {
  docker rm -f "$NAME-db" "$NAME-cli" >/dev/null 2>&1 || true
  docker network rm "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$NAME" >/dev/null
docker run -d --name "$NAME-db" --network "$NAME" \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:10.11 >/dev/null
docker run -d --name "$NAME-cli" --network "$NAME" --user root \
  -v "$REPO":/repo:ro --entrypoint sh wordpress:cli-php8.2 -c 'tail -f /dev/null' >/dev/null

wp() { docker exec -w /var/www/html "$NAME-cli" php -d memory_limit=512M /usr/local/bin/wp --allow-root "$@"; }

echo "== WordPress $WP_VERSION"
wp core download --version="$WP_VERSION" --quiet
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost="$NAME-db" --skip-check --quiet
wp config set WP_DEBUG true --raw --quiet
wp config set WP_DEBUG_DISPLAY true --raw --quiet
for _ in $(seq 1 30); do
  wp db check >/dev/null 2>&1 && break
  sleep 2
done
wp core install --url=http://localhost --title=Test --admin_user=admin --admin_password=admin \
  --admin_email=admin@example.org --skip-email --quiet
wp plugin install advanced-custom-fields --activate --quiet

docker exec "$NAME-cli" sh -c '
  mkdir -p /var/www/html/wp-content/mu-plugins &&
  cp /repo/tests/e2e/mu-plugin.php /var/www/html/wp-content/mu-plugins/eags-test.php &&
  cp -r /repo/ea-gcal-sync /var/www/html/wp-content/plugins/'
wp plugin activate ea-gcal-sync --quiet
echo "ACF $(wp plugin get advanced-custom-fields --field=version), PHP $(docker exec "$NAME-cli" php -r 'echo PHP_VERSION;')"

echo "== Test synchronizácie"
wp eval-file /repo/tests/e2e/test-sync.php

echo "== Odinštalovanie"
wp plugin deactivate ea-gcal-sync --quiet
wp plugin uninstall ea-gcal-sync --quiet
test -z "$(wp option get eags_settings 2>/dev/null)" && echo "  ok   nastavenia zmazané"
