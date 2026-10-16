ARG PHP_IMAGE=php:8.3-apache
FROM ${PHP_IMAGE}
RUN docker-php-ext-install mysqli \
    && php -r 'if (!extension_loaded("sodium")) exit(1);' \
    && if command -v a2enmod >/dev/null; then a2enmod ssl rewrite; fi \
    && mkdir -p /tmp/dancevault-uploads /tmp/dancevault-work \
    && chown www-data:www-data /tmp/dancevault-uploads /tmp/dancevault-work \
    && chmod 700 /tmp/dancevault-uploads /tmp/dancevault-work
COPY tests/integration/php.ini /usr/local/etc/php/conf.d/dancevault-integration.ini
