FROM python:3.13-alpine
ARG GETMAIL6_VERSION=6.21.1
RUN pip install --no-cache-dir getmail6==${GETMAIL6_VERSION} \
 && adduser -D -u 1000 getmail
COPY run.sh /usr/local/bin/run.sh
RUN chmod +x /usr/local/bin/run.sh
USER getmail
ENTRYPOINT ["/usr/local/bin/run.sh"]
