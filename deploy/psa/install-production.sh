#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this installer with sudo." >&2
    exit 1
fi

DEPLOY_ROOT=/opt/n45/psa
ENV_FILE="$DEPLOY_ROOT/.env"
COMPOSE_FILE="$DEPLOY_ROOT/app/deploy/psa/compose.yml"
INITIAL_CREDENTIAL_FILE=/home/n45admin/psa-initial-admin.txt

if [ ! -f "$COMPOSE_FILE" ]; then
    echo "Expected the N45 ITFlow checkout at $DEPLOY_ROOT/app." >&2
    exit 1
fi

install -d -m 0750 -o root -g root "$DEPLOY_ROOT"

if [ ! -f "$ENV_FILE" ]; then
    umask 077
    {
        printf 'ITFLOW_DB_PASSWORD=%s\n' "$(openssl rand -hex 32)"
        printf 'ITFLOW_DB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 32)"
        printf 'ITFLOW_IMAGE_TAG=production\n'
    } >"$ENV_FILE"
    chown root:root "$ENV_FILE"
    chmod 0600 "$ENV_FILE"
fi

compose() {
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

compose build --pull web
compose up -d --no-build db

db_container="$(compose ps -q db)"
db_ready=0
attempt=0
while [ "$attempt" -lt 60 ]; do
    if [ "$(docker inspect -f '{{.State.Health.Status}}' "$db_container" 2>/dev/null || true)" = healthy ]; then
        db_ready=1
        break
    fi
    attempt=$((attempt + 1))
    sleep 2
done

if [ "$db_ready" -ne 1 ]; then
    echo "MariaDB did not become healthy." >&2
    compose logs --tail 100 db >&2
    exit 1
fi

if ! compose run --rm --no-deps web test -f /var/lib/itflow/config.php >/dev/null 2>&1; then
    if [ -e "$INITIAL_CREDENTIAL_FILE" ]; then
        echo "Initial credential file already exists but ITFlow is not configured; refusing to overwrite it." >&2
        exit 1
    fi

    admin_password="$(openssl rand -hex 24)"
    db_password="$(sed -n 's/^ITFLOW_DB_PASSWORD=//p' "$ENV_FILE")"

    compose run --rm --no-deps web php scripts/setup_cli.php \
        --host=db \
        --username=itflow \
        --password="$db_password" \
        --database=itflow \
        --base-url=psa.n45tech.com \
        --locale=en_US \
        --timezone=America/New_York \
        --currency=USD \
        --company-name='N45 Technology Solutions' \
        --country='United States' \
        --company-email=hello@n45tech.com \
        --website=https://n45tech.com \
        --repo-branch=codex/level-integration \
        --user-name='Drew Hamilton' \
        --user-email=dhamilton@n45tech.com \
        --user-password="$admin_password" \
        --non-interactive

    credential_temp="$(mktemp)"
    printf 'URL: https://psa.n45tech.com\nUser: dhamilton@n45tech.com\nInitial vault password: %s\n' "$admin_password" >"$credential_temp"
    install -o n45admin -g n45admin -m 0600 "$credential_temp" "$INITIAL_CREDENTIAL_FILE"
    rm -f "$credential_temp"
fi

compose up -d --no-build

web_container="$(compose ps -q web)"
web_ready=0
attempt=0
while [ "$attempt" -lt 60 ]; do
    if [ "$(docker inspect -f '{{.State.Health.Status}}' "$web_container" 2>/dev/null || true)" = healthy ]; then
        web_ready=1
        break
    fi
    attempt=$((attempt + 1))
    sleep 2
done

if [ "$web_ready" -ne 1 ]; then
    echo "ITFlow did not become healthy." >&2
    compose logs --tail 100 web >&2
    exit 1
fi

compose ps
echo "ITFlow containers are healthy on 127.0.0.1:8088."
