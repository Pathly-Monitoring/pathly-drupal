# Image d'essai : PHP, Composer, Xdebug (couverture des branches) et SQLite.
ARG DRUPAL_IMAGE=drupal:10-apache
FROM ${DRUPAL_IMAGE}
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip sqlite3 $PHPIZE_DEPS \
 && pecl install xdebug \
 && docker-php-ext-enable xdebug \
 && apt-get purge -y --auto-remove $PHPIZE_DEPS \
 && rm -rf /var/lib/apt/lists/*
ENV XDEBUG_MODE=coverage COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1
