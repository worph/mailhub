# Cypht with MailHub's modules. Upstream image, plus:
#  - sabre/vobject, for reading and answering invitations (mailhub_invites);
#  - our module sets under modules/mailhub_* (enabled through CYPHT_MODULES);
#  - no Xdebug: the upstream image loads the debugger in production;
#  - a SQLite file the web server can write (upstream creates it as root).
ARG CYPHT_VERSION=2.13.1
FROM cypht/cypht:${CYPHT_VERSION}

WORKDIR /usr/local/share/cypht
ARG SABRE_VOBJECT_VERSION=4.5.6
RUN composer require --no-interaction --no-progress --update-no-dev "sabre/vobject:${SABRE_VOBJECT_VERSION}" \
 && rm -f /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

COPY modules/ modules/
COPY mailhub-entrypoint.sh /usr/local/bin/mailhub-entrypoint.sh
RUN chmod +x /usr/local/bin/mailhub-entrypoint.sh

ENTRYPOINT ["mailhub-entrypoint.sh"]
