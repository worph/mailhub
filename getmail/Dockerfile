FROM python:3.13-alpine
ARG GETMAIL6_VERSION=6.21.1
RUN pip install --no-cache-dir getmail6==${GETMAIL6_VERSION} \
 && adduser -D -H -u 33 -h /state getmail
COPY run.sh /usr/local/bin/run.sh
RUN chmod +x /usr/local/bin/run.sh
# uid 33 = www-data in the Roundcube image: the webmail writes the account files
# (Settings → Mail accounts) and getmail reads them, both as the same user, so
# the folder can stay private (0700). getmail needs a passwd entry for its uid.
USER getmail
ENTRYPOINT ["/usr/local/bin/run.sh"]
