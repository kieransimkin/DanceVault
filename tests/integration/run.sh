#!/usr/bin/env bash
set -euo pipefail
backend="${1:?apache or nginx}"
[[ "$backend" == apache || "$backend" == nginx ]]
root="$(cd "$(dirname "$0")/../.." && pwd)"
export DV_STATE="$root/tests/integration/.state/$backend"
export COMPOSE_PROJECT_NAME="dancevault_${backend}_${GITHUB_RUN_ID:-local}"
compose=(docker compose -f "$root/tests/integration/compose.yml" --profile "$backend")
mkdir -p "$DV_STATE/tls" "$DV_STATE/www" "$DV_STATE/evidence"
cleanup() {
    "${compose[@]}" logs --no-color > "$DV_STATE/evidence/server.log" 2>&1 || true
    "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT
curl --fail --silent --show-error --location https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli-2.12.0.phar -o "$DV_STATE/wp-cli.phar"
echo "ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c  $DV_STATE/wp-cli.phar" | sha256sum -c -
curl --fail --silent --show-error --location https://wordpress.org/wordpress-7.1.3.zip -o "$DV_STATE/core.zip"
unzip -q "$DV_STATE/core.zip" -d "$DV_STATE/core"
cp -a "$DV_STATE/core/wordpress/." "$DV_STATE/www/"
php "$DV_STATE/wp-cli.phar" core verify-checksums --path="$DV_STATE/www" --version=7.1.3
php "$DV_STATE/wp-cli.phar" config create --path="$DV_STATE/www" --dbname=vault_test --dbuser=vault_test --dbpass=synthetic-database-only --dbhost=db --skip-check --skip-salts
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 2 -keyout "$DV_STATE/tls/key.pem" -out "$DV_STATE/tls/cert.pem" -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' 2>/dev/null
unzip -q "$root"/dist/dancevault-*-wordpress.zip -d "$DV_STATE/www/wp-content/plugins"
printf 'Public media control; must remain unchanged.\n' > "$DV_STATE/www/public-control.txt"
mkdir -p "$DV_STATE/www/wp-content/uploads"
cp "$DV_STATE/www/public-control.txt" "$DV_STATE/www/wp-content/uploads/public-control.txt"
sudo chown -R 33:33 "$DV_STATE/www"
"${compose[@]}" up -d --build --wait
service=apache
[[ "$backend" == nginx ]] && service=php
wp=("${compose[@]}" exec -T --user www-data "$service" php /tools/wp-cli.phar --path=/var/www/html)
"${wp[@]}" core install --url=https://localhost:18843 --title='DanceVault disposable acceptance' --admin_user=vault-admin --admin_password='synthetic-admin-only-Strong-123' --admin_email=fixture@example.invalid --skip-email
"${wp[@]}" user create vault-editor editor@example.invalid --role=editor --user_pass='synthetic-editor-only-Strong-123'
"${wp[@]}" plugin activate dancevault
"${wp[@]}" eval-file /harness/control.php setup
"${wp[@]}" core version > "$DV_STATE/evidence/wordpress-version.txt"
"${compose[@]}" images > "$DV_STATE/evidence/container-images.txt"
python3 "$root/tests/integration/acceptance.py" "$backend" "$DV_STATE"
