#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this installer with sudo." >&2
    exit 1
fi

DEPLOY_USER="${1:-n45admin}"
APP=/opt/n45/psa/app
WRAPPER_SOURCE="$APP/deploy/psa/n45-psa-deploy-wrapper"
WRAPPER_TARGET=/usr/local/sbin/n45-psa-deploy
SUDOERS_TARGET=/etc/sudoers.d/n45-psa-github-deploy

id "$DEPLOY_USER" >/dev/null 2>&1 || {
    echo "Deployment user does not exist: $DEPLOY_USER" >&2
    exit 1
}
test -f "$WRAPPER_SOURCE"
command -v visudo >/dev/null

install -o root -g root -m 0755 "$WRAPPER_SOURCE" "$WRAPPER_TARGET"

sudoers_temp="$(mktemp)"
trap 'rm -f "$sudoers_temp"' EXIT
printf '%s ALL=(root) NOPASSWD: %s *\n' "$DEPLOY_USER" "$WRAPPER_TARGET" > "$sudoers_temp"
chmod 0440 "$sudoers_temp"
visudo -cf "$sudoers_temp"
install -o root -g root -m 0440 "$sudoers_temp" "$SUDOERS_TARGET"

echo "Installed restricted PSA deployment command for $DEPLOY_USER."
echo "GitHub may now invoke: sudo $WRAPPER_TARGET <main-commit-sha>"
