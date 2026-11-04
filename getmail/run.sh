#!/bin/sh
# Fetch every account in /config/*.getmailrc into Dovecot, every $FETCH_INTERVAL s,
# and sooner when the folder changes: an account added, edited or removed in the
# webmail (Settings → Mail accounts), or its "fetch now" file (/config/.fetch-now)
# touched, is fetched within ~5 s.
# Writes /state/status/<account>.json, which the webmail shows per account.
INTERVAL="${FETCH_INTERVAL:-300}"
mkdir -p /state/status

while true; do
  touch /state/.run-start
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
  # Forget the status of accounts that were removed.
  for s in /state/status/*.json; do
    [ -e "$s" ] || continue
    [ -e "/config/$(basename "$s" .json).getmailrc" ] || rm -f "$s"
  done
  # Anything in /config newer than the start of this run wakes the next one.
  mv /state/.run-start /state/.last-run

  waited=0
  while [ "$waited" -lt "$INTERVAL" ]; do
    sleep 5
    waited=$((waited + 5))
    [ -n "$(find /config -newer /state/.last-run 2>/dev/null | head -n 1)" ] && break
  done
done
