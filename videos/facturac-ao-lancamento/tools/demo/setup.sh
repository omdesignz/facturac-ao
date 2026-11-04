#!/usr/bin/env bash
# Builds a throwaway copy of the app with a year of believable trading, for filming.
#
#   tools/demo/setup.sh <dir>
#
# Everything lives in <dir>: a SQLite database, scratch signing keys, the owner's
# password and two-factor secret, and run.sh, which serves the site on
# 127.0.0.1:8123 for the simulator (launch the app with
# SIMCTL_CHILD_FACTURACAO_API_URL=http://127.0.0.1:8123). The development database
# is never touched, and nothing here can reach the AGT: jobs queue to the database
# with no worker, and the AGT's answers are replayed by the tests' own helper.
set -euo pipefail

dir="$(mkdir -p "$1" && cd "$1" && pwd)"
repo="$(cd "$(dirname "$0")/../../../.." && pwd)"
php="$HOME/Library/Application Support/Herd/bin/php84"

rm -rf "$dir"/*
mkdir -p "$dir/keys/software" "$dir/keys/taxpayer"
openssl genrsa -out "$dir/keys/software/demo.pem" 2048 2>/dev/null
openssl genrsa -out "$dir/keys/taxpayer/demo.pem" 2048 2>/dev/null
chmod 600 "$dir"/keys/*/*.pem
openssl rand -hex 12 | tr -d '\n' > "$dir/password.txt"
touch "$dir/demo.sqlite"

cat > "$dir/env.sh" <<EOF
export APP_ENV=local APP_DEBUG=false APP_URL=http://127.0.0.1:8123
export DB_CONNECTION=sqlite DB_DATABASE=$dir/demo.sqlite
export AGT_SIGNING_KEY_DIRECTORY=$dir/keys
export QUEUE_CONNECTION=database CACHE_STORE=file SESSION_DRIVER=file MAIL_MAILER=log
export DEMO_DIR=$dir
EOF

cat > "$dir/run.sh" <<EOF
#!/bin/sh
. "$dir/env.sh"
cd "$repo/public"
exec "$php" -d display_errors=stderr -S 127.0.0.1:8123 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
EOF
chmod +x "$dir/run.sh"

cat > "$dir/totp.sh" <<EOF
#!/bin/sh
# Prints the owner's current two-factor code.
cd "$repo" && "$php" -r 'require "vendor/autoload.php"; echo (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp(trim(file_get_contents("$dir/secret.txt"))), PHP_EOL;'
EOF
chmod +x "$dir/totp.sh"

cd "$repo"
(
  . "$dir/env.sh"
  "$php" artisan migrate --force --no-interaction >/dev/null
  "$php" -d memory_limit=1G "$repo/videos/facturac-ao-lancamento/tools/demo/seed.php"
)
