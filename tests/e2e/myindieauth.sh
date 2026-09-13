#!/usr/bin/env bash
#
# Run the server suite end to end against a local myindieauth.com, over real
# HTTPS, with no human in the loop. See README "End-to-end runs" for the nginx
# and /etc/hosts setup this expects.
#
#   tests/e2e/myindieauth.sh            # enroll if needed, then run
#   tests/e2e/myindieauth.sh --extended # include the extended tests
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TLS="$ROOT/storage/e2e-tls"

export BASE_URL="${BASE_URL:-https://indieauth-rocks.pad/}"
export ALT_BASE_URL="${ALT_BASE_URL:-https://redirect.indieauth-rocks.pad/}"
export FIXTURE_BASE_URL="${FIXTURE_BASE_URL:-https://e2e-profile.pad/}"
export ALLOW_LOOPBACK_FETCH=1

SERVER="${MYINDIEAUTH_BASE:-https://myindieauth.pad/}"
DRIVER_CONFIG="$ROOT/storage/drivers/myindieauth.json"

php_() { php -d curl.cainfo="$TLS/ca-bundle.pem" "$ROOT/bin/indieauth-rocks" "$@"; }

if [ ! -f "$DRIVER_CONFIG" ]; then
    php_ driver:myindieauth:enroll --base="$SERVER" --profile="$FIXTURE_BASE_URL" \
        --username="rocks-e2e-$(date +%s)" --ca-file="$TLS/ca-bundle.pem"
fi

php_ run-server "$FIXTURE_BASE_URL" --driver=myindieauth --ca-file="$TLS/ca-bundle.pem" "$@"
