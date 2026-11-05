#!/bin/sh
# Configures the SnappyMail spike after its first start (the image writes the
# default application.ini then). Run on the box: sh snappymail-setup.sh [app dir]
# Needs the Cypht spike running (its Dovecot and Baïkal) and SnappyMail's
# view-ics plugin source in ./view-ics (from the v3.0.0 tag, plugins/view-ics).
set -eu
APP=${1:-/DATA/AppData/snappymail-spike}
D=$APP/data/_data_/_default_
INI=$D/configs/application.ini
[ -f "$INI" ] || { echo "start the app once first ($INI missing)"; exit 1; }

# ini_set SECTION KEY VALUE: replace the key inside [SECTION] (value written as is)
ini_set() {
  awk -v s="[$1]" -v k="$2" -v v="$3" '
    /^\[/ { insec = ($0 == s) }
    insec && $0 ~ "^" k " *=" { print k " = " v; next }
    { print }' "$INI" > "$INI.new" && mv "$INI.new" "$INI"
}

ini_set webmail title '"SnappyMail (spike)"'
ini_set contacts enable On
ini_set contacts allow_sync On
ini_set contacts sync_interval 20
ini_set contacts type '"sqlite"'
ini_set plugins enable On
ini_set plugins enabled_list '"view-ics"'
# HTTPS is Caddy's job: since 3.0 force_https ignores X-Forwarded-Proto and loops
ini_set security force_https Off
ini_set login sign_me_auto '"DefaultOn"'
ini_set defaults mail_use_threads On

# Domains. Only these may log in (the default "*" stays disabled).
# TLS: SnappyMail does not verify certificates by default, we turn it on for providers.
tls_on='"ssl": {"verify_peer": true, "verify_peer_name": true, "allow_self_signed": false, "SNI_enabled": true, "disable_compression": true, "security_level": 1}'
tls_off='"ssl": {"verify_peer": false, "verify_peer_name": false, "allow_self_signed": false, "SNI_enabled": true, "disable_compression": true, "security_level": 1}'
sasl='"sasl": ["SCRAM-SHA-256", "SCRAM-SHA-1", "PLAIN", "LOGIN"]'

# domain_file NAME IMAPHOST IMAPPORT IMAPTYPE SMTPHOST SMTPPORT SMTPTYPE SHORTLOGIN TLS
# type: 0 plain, 1 SSL/TLS, 2 STARTTLS
domain_file() {
  cat > "$D/domains/$1.json" <<EOF
{
  "IMAP": {"host": "$2", "port": $3, "type": $4, "timeout": 300, "shortLogin": $8, "lowerLogin": true,
           $sasl, $9, "disabled_capabilities": ["METADATA", "OBJECTID", "PREVIEW", "STATUS=SIZE"],
           "use_expunge_all_on_delete": false, "fast_simple_search": true, "force_select": false,
           "message_all_headers": false, "message_list_limit": 10000, "search_filter": ""},
  "SMTP": {"host": "$5", "port": $6, "type": $7, "timeout": 60, "shortLogin": $8, "lowerLogin": true,
           $sasl, $9, "useAuth": true, "setSender": false, "usePhpMail": false},
  "Sieve": {"host": "$2", "port": 4190, "type": 0, "timeout": 10, "shortLogin": $8, "lowerLogin": true,
            $sasl, $9, "enabled": false},
  "whiteList": ""
}
EOF
}
# the real provider mailbox (PrivateEmail)
domain_file aptero.co mail.privateemail.com 993 1 mail.privateemail.com 465 1 false "$tls_on"
# the Cypht spike's Local archive (log in as admin@archive.local) and its test box
# (me@spike.test); internal network, no TLS, sending goes through the spike's Mailpit
domain_file archive.local cypht-spike-imap 31143 0 cypht-spike-mailpit 1025 0 true "$tls_off"
domain_file spike.test cypht-spike-imap 31143 0 cypht-spike-mailpit 1025 0 false "$tls_off"
sed -i 's/"useAuth": true/"useAuth": false/' "$D/domains/archive.local.json" "$D/domains/spike.test.json"

# the invitation viewer
if [ -d ./view-ics ]; then
  mkdir -p "$D/plugins" && rm -rf "$D/plugins/view-ics" && cp -r ./view-ics "$D/plugins/view-ics"
fi

chown -R 82:82 "$APP/data"
docker restart snappymail-spike >/dev/null
echo "done; admin password: $D/admin_password.txt"
