#!/bin/bash
# Gets a Claude Code on the web session ready to run the checks in CLAUDE.md: MariaDB for the
# integration tests, the PHP test tools in tests/ and the browser test packages in tests/browser.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"
DB_PASSWORD="kirjuritest"
log() { echo "session-start: $*" >&2; }

# MariaDB. The integration tests install a throwaway copy of Kirjuri into a new database, so the
# test user must be able to create and drop databases: root on a local server.
if ! command -v mariadbd >/dev/null 2>&1; then
  log "installing MariaDB"
  export DEBIAN_FRONTEND=noninteractive
  apt-get install -y -q mariadb-server >/dev/null 2>&1 || { apt-get update -q >/dev/null 2>&1 && apt-get install -y -q mariadb-server >/dev/null; }
fi
if ! mysqladmin -uroot -p"$DB_PASSWORD" -h127.0.0.1 ping >/dev/null 2>&1; then
  service mariadb start >/dev/null 2>&1 || service mysql start >/dev/null
  for _ in $(seq 1 30); do
    mysqladmin ping >/dev/null 2>&1 && break
    mysqladmin -uroot -p"$DB_PASSWORD" -h127.0.0.1 ping >/dev/null 2>&1 && break
    sleep 1
  done
fi
if ! mysqladmin -uroot -p"$DB_PASSWORD" -h127.0.0.1 ping >/dev/null 2>&1; then
  # A fresh server lets root in over the socket without a password; give it the tests' password.
  mysql -uroot -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('$DB_PASSWORD'); FLUSH PRIVILEGES;"
fi
mysqladmin -uroot -p"$DB_PASSWORD" -h127.0.0.1 ping >/dev/null
log "MariaDB is running"

if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
  {
    echo "export KIRJURI_TEST_DB_HOST=127.0.0.1"
    echo "export KIRJURI_TEST_DB_USER=root"
    echo "export KIRJURI_TEST_DB_PASSWORD=$DB_PASSWORD"
  } >> "$CLAUDE_ENV_FILE"
fi

# PHPUnit and PHPStan. The agent proxy refuses GitHub's zipball downloads, so packages come from
# their git sources. PHPStan has no source in the lock, only a zipball, so its locked commit is
# fetched with git and zipped into composer's cache first. Both steps are skipped once installed.
cd "$ROOT/tests"
if [ ! -x vendor/bin/phpunit ] || [ ! -x vendor/bin/phpstan ] || [ composer.lock -nt vendor/autoload.php ]; then
  read -r URL REF < <(php -r '$l = json_decode(file_get_contents("composer.lock"), true);
    foreach (array_merge($l["packages"], $l["packages-dev"]) as $p) { if ($p["name"] === "phpstan/phpstan") { echo $p["dist"]["url"], " ", $p["dist"]["reference"], "\n"; } }')
  CACHE="$(composer config cache-files-dir 2>/dev/null)/phpstan/phpstan"
  ZIP="$CACHE/$(php -r 'echo sha1($argv[1]);' "$URL").zip"
  if [ ! -s "$ZIP" ]; then
    log "fetching PHPStan $REF"
    WORK="$(mktemp -d)"
    git -C "$WORK" init -q
    git -C "$WORK" fetch -q --depth 1 https://github.com/phpstan/phpstan.git "$REF"
    mkdir -p "$CACHE"
    git -C "$WORK" archive --format=zip --prefix=phpstan/ -o "$ZIP" FETCH_HEAD
    rm -rf "$WORK"
  fi
  log "installing the PHP test tools (a few minutes the first time)"
  composer install --no-interaction --no-progress --prefer-source -q
fi
log "PHP test tools installed"

# Browser tests. Chromium is preinstalled at /opt/pw-browsers, so Playwright must not download one.
cd "$ROOT/tests/browser"
PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install --no-audit --no-fund --loglevel=error >/dev/null
log "browser test packages installed"
