#!/usr/bin/env bash
# Starts the PHP-FPM test site of the nightly job (compose.yml) and waits until WordPress answers on localhost:8080.
set -euo pipefail
cd "$(dirname "$0")"
docker compose -f compose.yml up -d db wordpress nginx
for i in $(seq 1 90); do
	code=$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/wp-admin/install.php || true)
	if [ "$code" = "200" ] || [ "$code" = "302" ]; then
		echo "WordPress answers ($code)."
		exit 0
	fi
	sleep 2
done
docker compose -f compose.yml logs --no-color --tail=100
echo "WordPress did not answer on localhost:8080" >&2
exit 1
