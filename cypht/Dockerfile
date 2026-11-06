# Cypht with MailHub's modules. Upstream image, plus:
#  - sabre/vobject, for reading and answering invitations (mailhub_invites);
#  - our module sets under modules/mailhub_* (enabled through CYPHT_MODULES), with
#    FullCalendar (MIT) for mailhub_calendar's page, fetched by version and checksum;
#  - no Xdebug: the upstream image loads the debugger in production;
#  - a SQLite file the web server can write (upstream creates it as root);
#  - TLS certificates checked for every mail server with a dotted host name (upstream checks none);
#    a bare compose service name (cypht-imap, a test catcher) stays unchecked, it never leaves the host;
#  - the private bridge of cypht-gateway (github.com/ashanzzz/cypht-gateway), which lets the gateway
#    and its MCP server use the accounts configured here. Enabled with `api_login,gateway` in CYPHT_MODULES.
ARG CYPHT_VERSION=2.13.1
# cypht-gateway 0.6.0 by commit: upstream deleted its v0.6.0 tag (newest tag is v0.5.2).
ARG CYPHT_GATEWAY_COMMIT=24440aff402ba0c85f484f5872fd837220be0082

FROM alpine/git:v2.47.2 AS gateway
ARG CYPHT_GATEWAY_COMMIT
RUN git init -q /src && cd /src \
 && git fetch -q --depth 1 https://github.com/ashanzzz/cypht-gateway.git "${CYPHT_GATEWAY_COMMIT}" \
 && git checkout -q FETCH_HEAD && test "$(cat VERSION)" = 0.6.0

FROM cypht/cypht:${CYPHT_VERSION}

WORKDIR /usr/local/share/cypht
ARG SABRE_VOBJECT_VERSION=4.5.6
RUN composer require --no-interaction --no-progress --update-no-dev "sabre/vobject:${SABRE_VOBJECT_VERSION}" \
 && rm -f /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

RUN set -e; \
    sed -i 's/\$this->verify_peer_name);/$this->verify_peer_name || strpos($this->server, ".") !== false);/; s/\$this->verify_peer);/$this->verify_peer || strpos($this->server, ".") !== false);/' modules/imap/hm-imap.php; \
    sed -i "s/'verify_peer_name', false);/'verify_peer_name', strpos(\$server, '.') !== false);/; s/'verify_peer', false);/'verify_peer', strpos(\$server, '.') !== false);/" modules/smtp/hm-smtp.php; \
    test "$(grep -c 'strpos($this->server, ".") !== false' modules/imap/hm-imap.php)" = 2; \
    test "$(grep -c "strpos(\$server, '.') !== false" modules/smtp/hm-smtp.php)" = 2

COPY --from=gateway /src/cypht-module/gateway modules/gateway
COPY --from=gateway /src/docker/cypht-gateway-config.php config/gateway.php
RUN printf 'auto_prepend_file=/usr/local/share/cypht/modules/gateway/bootstrap.php\n' > /usr/local/etc/php/conf.d/cypht-gateway.ini

COPY modules/ modules/
ARG FULLCALENDAR_VERSION=6.1.22
ARG FULLCALENDAR_SHA256=65f927f1802cb35428b9c5e2a8d2276633880a89ee5ea5f9a7727f79892786ea
RUN mkdir -p modules/mailhub_calendar/assets \
 && curl -fsSL -o modules/mailhub_calendar/assets/fullcalendar.min.js \
      "https://cdn.jsdelivr.net/npm/fullcalendar@${FULLCALENDAR_VERSION}/index.global.min.js" \
 && echo "${FULLCALENDAR_SHA256}  modules/mailhub_calendar/assets/fullcalendar.min.js" | sha256sum -c -
COPY mailhub-entrypoint.sh /usr/local/bin/mailhub-entrypoint.sh
RUN chmod +x /usr/local/bin/mailhub-entrypoint.sh

ENTRYPOINT ["mailhub-entrypoint.sh"]
