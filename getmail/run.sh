#!/bin/sh
# Fetch every account in /config/*.getmailrc into Dovecot, every $FETCH_INTERVAL s.
# Writes /state/status/<account>.json so a UI (later: the mailhub service) can
# show per-account health, e.g. an app password that stopped working.
INTERVAL="${FETCH_INTERVAL:-300}"
mkdir -p /state/status

while true; do
  for rc in /config/*.getmailrc; do
    [ -e "$rc" ] || continue
    name=$(basename "$rc" .getmailrc)
    out=$(getmail --getmaildir /state --rcfile "$rc" 2>&1)
    code=$?
    printf '%s\n' "$out" | sed "s/^/[$name] /"
    msg=$(printf '%s' "$out" | tail -n 1 | sed 's/\\/\\\\/g; s/"/\\"/g')
    printf '{"account":"%s","ok":%s,"exit":%d,"at":"%s","last":"%s"}\n' \
      "$name" "$([ $code -eq 0 ] && echo true || echo false)" "$code" \
      "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$msg" > "/state/status/$name.json"
  done
  sleep "$INTERVAL"
done
