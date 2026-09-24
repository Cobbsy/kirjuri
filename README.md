# SECURITY UPDATE (7.5.2021)

As this project has been inactive for years, it was inevitable that some of the dependencies will become out of date. There are several security vulnerabilities in the dependencies involved, and some of the dependencies, like Twig, don't play nice with the newest version of PHP. IF you wish to install and use Kirjuri, please update the dependencies manually & make sure you install it in a safe environment. The original advice was to install in an [air-gapped environment](https://en.wikipedia.org/wiki/Air_gap_(networking) and this advice still stands.

As always, I can not guarantee the security of this software, and any users will be solely responsible of configuring it securely before using. There have been several attempts by well-meaning individuals to develop this project further, but they have all been deterred from it by the quality of the code and the lack of proper commenting.

**Use Kirjuri at your own risk.**

# Kirjuri

Kirjuri is a simple php/mysql web application for managing physical forensic evidence items. It is intended to be used as a workflow tool from receiving, booking, note-taking and possibly reporting findings. It simplifies and helps in case management when dealing with a large (or small!) number of devices submitted for forensic analysis. Kirjuri requires PHP 8.1 or newer with the pdo_mysql, mbstring and openssl extensions (and ldap if you use LDAP authentication).

The `conf/`, `logs/` and `cache/` folders hold credentials, audit logs and session data. They ship with `.htaccess` files that deny web access on Apache. On other web servers, deny access to them in the server configuration, for example on nginx: `location ~ ^/(conf|logs|cache)/ { deny all; }`

[See the official Kirjuri home page for more details.](https://kurittu.org/2018/06/kirjuri/)

NOTICE: Kirjuri is no longer actively developed since 09/2017, as I don't have time for this project anymore. If you are interested in developing this tool further, please contact me.

OVERVIEW & LICENSE
------------

Kirjuri is developed by Antti Kurittu. It was started at the Helsinki Police Department as an internal tool. Original development released under the MIT license. Some components are distributed with their own licenses, please see folders & help for details.

RUNNING WITH DOCKER
------------

`docker compose up` starts Kirjuri with MariaDB at http://localhost:8080 (set `KIRJURI_PORT` for another port). It installs itself on the first start; log in as `admin` with the `KIRJURI_ADMIN_PASSWORD` from `docker-compose.yml`. The source folder is mounted into the container, so edits show up on reload, and pending migrations are applied on every start.

The compose file is meant for development and testing: change the passwords, turn off `show_errors` and put a TLS proxy in front before using it for real data. `docker/smoke-test.sh` checks a running container, and CI runs it on every pull request.

Without Docker, `php bin/kirjuri install` installs from a shell, reading `KIRJURI_DB_HOST`, `KIRJURI_DB_NAME`, `KIRJURI_DB_USER`, `KIRJURI_DB_PASSWORD` and `KIRJURI_ADMIN_PASSWORD` from the environment.

COMMAND LINE TOOL AND TROUBLESHOOTING
------------

`bin/kirjuri` handles maintenance from a shell on the server. Run it as the web server user so files it creates stay writable, e.g. `sudo -u www-data php bin/kirjuri doctor`.

```
php bin/kirjuri install                     # install without a browser (settings from KIRJURI_* variables)
php bin/kirjuri doctor                      # check PHP, extensions, folder permissions, settings and the database
php bin/kirjuri migrate [--status]          # apply or list database migrations
php bin/kirjuri user:list
php bin/kirjuri user:create <username> <name> <access 0-3> [--api]   # password from stdin
php bin/kirjuri user:password <username>    # reset a password (e.g. a locked out admin) from stdin
php bin/kirjuri user:unlock <username>      # clear the failed login counter
php bin/kirjuri errors [--id <id>] [--last <n>]
php bin/kirjuri log [--case <uid>] [--last <n>]
php bin/kirjuri audit:show <audit file>     # decrypt an audit log entry
php bin/kirjuri cache:clear
```

When a page fails, Kirjuri shows a reference like `3f9a1c0b77de`, which is also sent in the `X-Request-Id` header. `php bin/kirjuri errors --id 3f9a1c0b77de` prints the full error and stack trace from `logs/error.log`.

Database changes are versioned migrations in `lib/migrations.php`. After upgrading Kirjuri, pending migrations are applied on the next page load, or run `php bin/kirjuri migrate` first. You no longer need to rerun `install.php`.

TESTING
------------

The tests live in `tests/` and have their own `composer.json`, so the committed `vendor/` folder stays free of development dependencies.

```
cd tests
composer install
vendor/bin/phpunit --testsuite unit
```

The unit tests need nothing else. The integration tests install a throwaway copy of Kirjuri into a new database, run it with PHP's built-in web server and drive it over HTTP. They need a MySQL or MariaDB user that can create and drop databases, and are skipped when it is not configured:

```
KIRJURI_TEST_DB_HOST=127.0.0.1 KIRJURI_TEST_DB_USER=root KIRJURI_TEST_DB_PASSWORD=secret vendor/bin/phpunit
```

Static analysis runs with `vendor/bin/phpstan` from the same folder.

All SQL lives in the `lib/` modules (cases, devices, attachments, messages, tools, users and so on); pages and the files in `actions/` call their functions. A unit test fails if code outside `lib/` prepares or runs a query or opens its own database connection, so new queries go into a `lib/` function where they can be found, reviewed and tested together.

The temporary installation and database are removed afterwards. Set `KIRJURI_TEST_KEEP=1` to keep the installation folder for debugging. GitHub Actions runs the whole suite on every push and pull request.

CHANGELOG
------------

see [CHANGELOG.md](CHANGELOG.md)

LOOKING TO PARTICIPATE?
------------
* Everyone interested is encouraged to submit code and enhancements. If you don't feel confident submitting code, you can submit lanugage files and localized lists of devices etc. These will gladly be accepted.

SCREENSHOTS
------------

![1](https://github.com/AnttiKurittu/kirjuri/blob/master/extra/screenshots/1.png)
![2](https://github.com/AnttiKurittu/kirjuri/blob/master/extra/screenshots/2.png)
![3](https://github.com/AnttiKurittu/kirjuri/blob/master/extra/screenshots/3.png)
![4](https://github.com/AnttiKurittu/kirjuri/blob/master/extra/screenshots/4.png)
![5](https://github.com/AnttiKurittu/kirjuri/blob/master/extra/screenshots/5.png)
![6](https://github.com/AnttiKurittu/kirjuri/blob/master/extra/screenshots/6.png)
![7](https://github.com/AnttiKurittu/kirjuri/blob/master/extra/screenshots/7.png)
![8](https://github.com/AnttiKurittu/kirjuri/blob/master/extra/screenshots/8.png)
![9](https://github.com/AnttiKurittu/kirjuri/blob/master/extra/screenshots/9.png)
