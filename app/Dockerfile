FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
 && docker-php-ext-install pdo_mysql mbstring curl \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers

ENV APACHE_DOCUMENT_ROOT=/var/www/tutora/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'expose_php=Off\nsession.use_strict_mode=1\nsession.use_only_cookies=1\nupload_max_filesize=50M\npost_max_size=52M\nmax_file_uploads=1\n' > "$PHP_INI_DIR/conf.d/tutora.ini"

WORKDIR /var/www/tutora
