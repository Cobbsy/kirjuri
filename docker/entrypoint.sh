#!/bin/sh
# Install Kirjuri on first start, apply pending migrations on later starts, then run the web server.
set -e
cd /var/www/html

# With the source mounted from the host, make the folders Kirjuri writes to writable by Apache.
mkdir -p logs/audit cache
chown -R www-data:www-data conf logs cache

run_cli() {
    # Run as the web server user so the files it creates stay writable by Apache.
    su -s /bin/sh www-data -c "php bin/kirjuri $*"
}

if [ ! -f conf/mysql_credentials.php ]; then
    if [ -z "$KIRJURI_ADMIN_PASSWORD" ]; then
        echo "Kirjuri is not installed. Set KIRJURI_ADMIN_PASSWORD (and the KIRJURI_DB_* variables) to install it on start," >&2
        echo "or open install.php in a browser." >&2
    else
        # The database container may still be starting.
        tries=0
        until run_cli install; do
            tries=$((tries + 1))
            if [ "$tries" -ge 30 ]; then
                echo "Kirjuri install failed." >&2
                exit 1
            fi
            echo "Waiting for the database..." >&2
            sleep 2
        done
    fi
else
    run_cli migrate
fi

exec "$@"
