# Kirjuri on PHP 8.3 with Apache. See docker-compose.yml and the README for use.
FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod headers

# The stock image ignores .htaccess files; Kirjuri's deny access to conf/, logs/, cache/ and code folders.
RUN printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-enabled/kirjuri.conf \
    && printf 'expose_php = Off\nupload_max_filesize = 16M\npost_max_size = 20M\n' > /usr/local/etc/php/conf.d/kirjuri.ini

COPY docker/entrypoint.sh /usr/local/bin/kirjuri-entrypoint
RUN chmod +x /usr/local/bin/kirjuri-entrypoint

COPY . /var/www/html
RUN chown -R www-data:www-data /var/www/html/conf /var/www/html/logs /var/www/html/cache

ENTRYPOINT ["kirjuri-entrypoint"]
CMD ["apache2-foreground"]
