#!/bin/sh
# End-to-end check of the actual Apache vhost. Requires Docker and curl.
set -eu
project=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
fixture=$(mktemp -d)
container=""
cleanup() {
    if [ -n "$container" ]; then docker rm -f "$container" >/dev/null; fi
    # fixture is exclusively the directory returned by mktemp above.
    case "$fixture" in /tmp/*|"${TMPDIR:-/tmp}"/*) rm -rf -- "$fixture" ;; esac
}
trap cleanup EXIT HUP INT TERM
docker build -t h5ai-auth-security-test "$project/deploy"
mkdir -p "$fixture/root/_h5ai" "$fixture/root/folder" "$fixture/state"
cp -R "$project/private" "$project/public" "$fixture/root/_h5ai/"
printf '%s' 'PRIVATE-CONTENT' > "$fixture/root/document.txt"
printf '%s' '<?php echo "EXECUTED"; ?>' > "$fixture/root/shared.php"
printf '%s' 'PRIVATE-THUMB' > "$fixture/root/_h5ai/public/cache/test.jpg"
printf '%s' '"/var/www/html/document.txt"' > "$fixture/root/_h5ai/public/cache/test.jpg.source.json"
printf '%s' 'PRIVATE-HIDDEN' > "$fixture/root/.secret"
printf '%s\n' 'Require all granted' > "$fixture/root/folder/.htaccess"
ln -s /etc/passwd "$fixture/root/link.txt"
docker run --rm h5ai-auth-security-test php -r 'echo "tester:", password_hash("Test-Password-2026!", PASSWORD_BCRYPT), "\n";' > "$fixture/users.htpasswd"
chmod -R a+rX "$fixture"
chmod -R a+rwX "$fixture/root/_h5ai/private/cache" "$fixture/root/_h5ai/public/cache" "$fixture/state"
container=$(docker run -d --rm -p 127.0.0.1::80 \
    -e H5AI_AUTH_FILE=/run/secrets/users.htpasswd -e H5AI_AUTH_STATE=/auth-state -e H5AI_USERS_DIR=/auth-state/users \
    -v "$fixture/root:/var/www/html" -v "$fixture/users.htpasswd:/run/secrets/users.htpasswd:ro" \
    -v "$fixture/state:/auth-state" h5ai-auth-security-test)
port=$(docker port "$container" 80/tcp | sed 's/.*://')
base="http://127.0.0.1:$port"
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    if curl -fsS "$base/" > "$fixture/page"; then break; fi
    sleep 1
done
docker exec "$container" apache2ctl -t
for path in / /document.txt /shared.php /folder/ /_h5ai/public/cache/test.jpg; do
    curl -fsS -c "$fixture/cookies" "$base$path" > "$fixture/page"
    grep -q 'name="username"' "$fixture/page"
    if grep -q 'PRIVATE-\|EXECUTED' "$fixture/page"; then echo "Anonymous leak: $path"; exit 1; fi
done
curl -fsS -b "$fixture/cookies" -c "$fixture/cookies" "$base/" > "$fixture/page"
token=$(sed -n 's/.*name="_csrf" value="\([a-f0-9]*\)".*/\1/p' "$fixture/page")
status=$(curl -sS -b "$fixture/cookies" -c "$fixture/cookies" -o /dev/null -w '%{http_code}' \
    --data-urlencode h5ai_auth=login --data-urlencode "_csrf=$token" \
    --data-urlencode username=tester --data-urlencode 'password=Test-Password-2026!' "$base/")
[ "$status" = 303 ]
[ "$(curl -fsS -b "$fixture/cookies" "$base/document.txt")" = PRIVATE-CONTENT ]
[ "$(curl -fsS -b "$fixture/cookies" "$base/_h5ai/public/cache/test.jpg")" = PRIVATE-THUMB ]
[ "$(curl -fsS -b "$fixture/cookies" -H 'Range: bytes=0-6' "$base/document.txt")" = PRIVATE ]
curl -fsS "$base/_h5ai/public/js/scripts.js" > /dev/null
for path in /.secret /shared.php /link.txt /_h5ai/private/conf/options.json /_h5ai/deploy/apache-h5ai.conf; do
    [ "$(curl -sS -b "$fixture/cookies" -o /dev/null -w '%{http_code}' "$base$path")" = 404 ]
done
curl -fsS -b "$fixture/cookies" "$base/folder/" > "$fixture/page"
token=$(sed -n 's/.*name="h5ai-csrf" content="\([a-f0-9]*\)".*/\1/p' "$fixture/page")
[ -n "$token" ]
[ "$(curl -sS -b "$fixture/cookies" -o /dev/null -w '%{http_code}' -d 'action=get' "$base/")" = 403 ]
[ "$(curl -sS -b "$fixture/cookies" -o /dev/null -w '%{http_code}' "$base/_h5ai/public/index.php?admin=1")" = 404 ]
[ "$(curl -sS -b "$fixture/cookies" -o /dev/null -w '%{http_code}' -d "h5ai_auth=logout&_csrf=$token" "$base/deconnexion")" = 303 ]
curl -fsS -b "$fixture/cookies" "$base/document.txt" > "$fixture/page"
grep -q 'name="username"' "$fixture/page"
echo 'Apache Docker security checks passed.'
