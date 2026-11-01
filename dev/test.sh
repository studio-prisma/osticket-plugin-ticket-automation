#!/usr/bin/env bash
# Unit tests, then the integration suite against the stack of dev/up.sh.
#
#   ./dev/up.sh && ./dev/test.sh
#
# The unit tests run twice: with the PHP on this machine (if any) and with
# the container's PHP 8.3, which has mbstring like every osTicket host.
set -uo pipefail
cd "$(dirname "$0")"
# Relative host paths only: Git Bash would otherwise rewrite the container
# paths below into Windows paths.
export MSYS_NO_PATHCONV=1
COMPOSE=(docker compose --env-file local/credentials.env -f docker-compose.yml)
rc=0

if command -v php >/dev/null; then
  echo "== unit (local PHP $(php -r 'echo PHP_VERSION;'))"
  php ../tests/unit.php > local/unit-local.out; r=$?
  tail -1 local/unit-local.out; [ $r -eq 0 ] || { grep '^FAIL' local/unit-local.out; rc=1; }
fi
echo "== unit (container PHP)"
"${COMPOSE[@]}" exec -T -e PLUGIN_DIR=/var/www/html/include/plugins/ticket-automation osticket \
  php /tests/unit.php > local/unit-container.out; r=$?
tail -1 local/unit-container.out; [ $r -eq 0 ] || { grep '^FAIL' local/unit-container.out; rc=1; }
echo "== integration"
"${COMPOSE[@]}" exec -T osticket php /tests/integration/run.php || rc=1
exit $rc
