# syntax=docker/dockerfile:1

FROM prestashop/prestashop:1.7.8

ARG HOST_UID=1000
ARG HOST_GID=1000

ENV COMPOSER_HOME=/var/www/.composer

RUN groupmod --gid "${HOST_GID}" www-data \
    && usermod --uid "${HOST_UID}" --gid "${HOST_GID}" www-data

RUN mkdir "${COMPOSER_HOME}" \
    && chown www-data:www-data "${COMPOSER_HOME}"

RUN chown -R www-data:www-data /tmp/data-ps/prestashop/

COPY --link --from=mlocati/php-extension-installer \
    /usr/bin/install-php-extensions \
    /usr/local/bin/install-php-extensions

RUN install-php-extensions @composer

COPY --link --chmod=644 --chown=www-data:www-data \
    tmp/defines_custom.inc.php \
    /tmp/defines_custom.inc.php

COPY --link --chmod=755 bootstrap.sh /var/www/bootstrap.sh

CMD ["/var/www/bootstrap.sh"]
