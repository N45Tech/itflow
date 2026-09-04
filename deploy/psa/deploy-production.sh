#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

if [ "$EUID" -ne 0 ]; then
    echo "Production deployment must run as root." >&2
    exit 1
fi

if [ "$#" -ne 1 ] || [[ ! "$1" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Usage: deploy-production.sh <40-character-main-commit-sha>" >&2
    exit 1
fi

TARGET_SHA="$1"
APP=/opt/n45/psa/app
ENV_FILE=/opt/n45/psa/.env
COMPOSE_FILE="$APP/deploy/psa/compose.yml"
EVIDENCE_ROOT=/opt/n45/psa/release-evidence
DEPLOYED_MARKER=/opt/n45/psa/deployed-sha
PUBLIC_URL=https://psa.n45tech.com/

for command_name in curl docker flock git sha256sum sudo tar; do
    command -v "$command_name" >/dev/null || {
        echo "Required command is unavailable: $command_name" >&2
        exit 1
    }
done

if [ "${N45_DEPLOY_LOCK_HELD:-0}" != 1 ]; then
    exec 9>/run/lock/n45-psa-deploy.lock
    flock -n 9 || {
        echo "Another PSA production deployment is already running." >&2
        exit 1
    }
fi

test -f "$ENV_FILE"
test -f "$COMPOSE_FILE"
install -d -o root -g root -m 0750 "$EVIDENCE_ROOT"

dc() {
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

env_value() {
    sed -n "s/^$1=//p" "$ENV_FILE" | tail -n 1
}

set_env() {
    local key="$1"
    local value="$2"
    if grep -q "^${key}=" "$ENV_FILE"; then
        sed -i "s/^${key}=.*/${key}=${value}/" "$ENV_FILE"
    else
        printf '\n%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
}

web_health() {
    local container_id
    container_id="$(dc ps -q web)"
    if [ -z "$container_id" ]; then
        printf 'missing\n'
        return
    fi
    docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id"
}

wait_for_web() {
    local attempt
    for attempt in $(seq 1 60); do
        if [ "$(web_health)" = healthy ]; then
            return 0
        fi
        sleep 2
    done
    dc logs --tail 100 web >&2 || true
    return 1
}

http_status() {
    curl -sS -o /dev/null -w '%{http_code}' "$1" || printf '000'
}

http_is_healthy() {
    [[ "$1" =~ ^(2|3)[0-9][0-9]$ ]]
}

REPO_OWNER="$(stat -c '%U' "$APP/.git")"
PREVIOUS_HEAD="$(sudo -u "$REPO_OWNER" git -c safe.directory="$APP" -C "$APP" rev-parse HEAD)"
PREVIOUS_TAG="$(env_value ITFLOW_IMAGE_TAG)"
PREVIOUS_LEVEL="$(env_value N45_FEATURE_LEVEL)"
PREVIOUS_AUTOMATION="$(env_value N45_FEATURE_AUTOMATION)"
PREVIOUS_LEVEL="${PREVIOUS_LEVEL:-1}"
PREVIOUS_AUTOMATION="${PREVIOUS_AUTOMATION:-1}"
PREVIOUS_CRON_RUNNING=0
if [ -n "$(dc ps --status running -q cron)" ]; then
    PREVIOUS_CRON_RUNNING=1
fi

CURRENT_WEB_CONTAINER="$(dc ps -a -q web)"
test -n "$CURRENT_WEB_CONTAINER"
CURRENT_IMAGE="$(docker inspect --format '{{.Config.Image}}' "$CURRENT_WEB_CONTAINER")"
APP_DATA_VOLUME="$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/itflow"}}{{.Name}}{{end}}{{end}}' "$CURRENT_WEB_CONTAINER")"
test -n "$APP_DATA_VOLUME"
PREVIOUS_TAG="${PREVIOUS_TAG:-${CURRENT_IMAGE#n45-itflow:}}"

if [ -f "$DEPLOYED_MARKER" ] \
    && grep -qx "$TARGET_SHA" "$DEPLOYED_MARKER" \
    && [ "$CURRENT_IMAGE" = "n45-itflow:$TARGET_SHA" ] \
    && [ "$(web_health)" = healthy ]; then
    echo "Release $TARGET_SHA is already deployed and healthy."
    exit 0
fi

if [ -n "$(sudo -u "$REPO_OWNER" git -c safe.directory="$APP" -C "$APP" status --porcelain)" ]; then
    echo "The production checkout has uncommitted changes; refusing deployment." >&2
    exit 1
fi

sudo -u "$REPO_OWNER" git -c safe.directory="$APP" -C "$APP" fetch --no-tags origin main
REMOTE_SHA="$(sudo -u "$REPO_OWNER" git -c safe.directory="$APP" -C "$APP" rev-parse FETCH_HEAD)"
test "$REMOTE_SHA" = "$TARGET_SHA"

EVIDENCE="$EVIDENCE_ROOT/${TARGET_SHA:0:10}-$(date -u +%Y%m%dT%H%M%SZ)"
install -d -o root -g root -m 0750 "$EVIDENCE"
exec > >(tee -a "$EVIDENCE/deploy.log") 2>&1

WRITERS_STOPPED=0
DB_MUTATED=0
TARGET_IMAGE_BUILT=0
PHASE=preflight

handle_failure() {
    local exit_code="$?"
    local line_number="$1"
    trap - ERR
    set +e
    echo "DEPLOYMENT_FAILED phase=$PHASE line=$line_number exit=$exit_code" >&2

    if [ "$WRITERS_STOPPED" -eq 1 ]; then
        dc stop cron >/dev/null 2>&1 || true
        if [ "$DB_MUTATED" -eq 0 ]; then
            echo "Restoring the previous code and container image; the database was not changed."
            set_env ITFLOW_IMAGE_TAG "$PREVIOUS_TAG"
            set_env N45_FEATURE_LEVEL "$PREVIOUS_LEVEL"
            set_env N45_FEATURE_AUTOMATION "$PREVIOUS_AUTOMATION"
            sudo -u "$REPO_OWNER" git -c safe.directory="$APP" -C "$APP" checkout --detach "$PREVIOUS_HEAD" || true
            dc up -d --no-deps --force-recreate web || true
            if [ "$PREVIOUS_CRON_RUNNING" -eq 1 ]; then
                dc up -d --no-deps --force-recreate cron || true
            fi
        elif [ "$TARGET_IMAGE_BUILT" -eq 1 ]; then
            echo "Database work began; leaving the new web image in safe mode and cron stopped."
            set_env ITFLOW_IMAGE_TAG "$TARGET_SHA"
            set_env N45_FEATURE_LEVEL 0
            set_env N45_FEATURE_AUTOMATION 0
            dc up -d --no-deps --force-recreate web || true
        else
            echo "Database work began before a usable target image was available; writers remain stopped." >&2
        fi
    fi

    echo "EVIDENCE=$EVIDENCE" >&2
    exit "$exit_code"
}
trap 'handle_failure "$LINENO"' ERR

{
    echo "target_sha=$TARGET_SHA"
    echo "previous_head=$PREVIOUS_HEAD"
    echo "previous_image=$CURRENT_IMAGE"
    echo "previous_level=$PREVIOUS_LEVEL"
    echo "previous_automation=$PREVIOUS_AUTOMATION"
    echo "previous_cron_running=$PREVIOUS_CRON_RUNNING"
    echo "started_at_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
} > "$EVIDENCE/release.txt"

PHASE=stop-writers
echo '=== STOP APPLICATION WRITERS ==='
dc stop web cron
WRITERS_STOPPED=1
test -z "$(dc ps --status running -q web)"
test -z "$(dc ps --status running -q cron)"

DB_CONTAINER="$(dc ps -q db)"
test -n "$DB_CONTAINER"
test "$(docker inspect --format '{{.State.Health.Status}}' "$DB_CONTAINER")" = healthy

PHASE=database-snapshot
echo '=== DATABASE SNAPSHOT AND RESTORE PROOF ==='
DUMP_FILE="$EVIDENCE/itflow.sql"
docker exec "$DB_CONTAINER" sh -lc \
    'exec mariadb-dump --single-transaction --routines --events --triggers --hex-blob -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
    > "$DUMP_FILE.tmp"
test -s "$DUMP_FILE.tmp"
grep -q '^-- Dump completed on ' "$DUMP_FILE.tmp"
mv "$DUMP_FILE.tmp" "$DUMP_FILE"
sha256sum "$DUMP_FILE" | tee "$EVIDENCE/itflow.sql.sha256"

RESTORE_DB="n45_restore_${TARGET_SHA:0:8}_$$"
docker exec "$DB_CONTAINER" sh -lc \
    "mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" -e 'CREATE DATABASE \`$RESTORE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'"
if ! docker exec -i "$DB_CONTAINER" sh -lc \
    "exec mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" '$RESTORE_DB'" < "$DUMP_FILE"; then
    docker exec "$DB_CONTAINER" sh -lc \
        "mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" -e 'DROP DATABASE IF EXISTS \`$RESTORE_DB\`'" || true
    false
fi
LIVE_CONTACTS="$(docker exec "$DB_CONTAINER" sh -lc \
    'mariadb -N -B -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "SELECT COUNT(*) FROM contacts"')"
RESTORED_CONTACTS="$(docker exec "$DB_CONTAINER" sh -lc \
    "mariadb -N -B -uroot -p\"\$MARIADB_ROOT_PASSWORD\" '$RESTORE_DB' -e 'SELECT COUNT(*) FROM contacts'")"
test "$LIVE_CONTACTS" = "$RESTORED_CONTACTS"
docker exec "$DB_CONTAINER" sh -lc \
    "mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" -e 'DROP DATABASE \`$RESTORE_DB\`'"
echo "RESTORE_PROOF_CONTACTS=$RESTORED_CONTACTS"

PHASE=application-data-snapshot
echo '=== APPLICATION DATA SNAPSHOT ==='
docker run --rm --entrypoint sh \
    -v "$APP_DATA_VOLUME:/source:ro" \
    -v "$EVIDENCE:/backup" \
    "$CURRENT_IMAGE" \
    -lc 'tar -C /source -czf /backup/psa_app_data.tgz .'
docker run --rm --entrypoint sh \
    -v "$EVIDENCE:/backup:ro" \
    "$CURRENT_IMAGE" \
    -lc 'tar -tzf /backup/psa_app_data.tgz >/dev/null'
sha256sum "$EVIDENCE/psa_app_data.tgz" | tee "$EVIDENCE/psa_app_data.tgz.sha256"

PHASE=build
echo '=== BUILD EXACT RELEASE ==='
sudo -u "$REPO_OWNER" git -c safe.directory="$APP" -C "$APP" checkout --detach "$TARGET_SHA"
test "$(sudo -u "$REPO_OWNER" git -c safe.directory="$APP" -C "$APP" rev-parse HEAD)" = "$TARGET_SHA"
set_env ITFLOW_IMAGE_TAG "$TARGET_SHA"
set_env N45_FEATURE_LEVEL 0
set_env N45_FEATURE_AUTOMATION 0
dc build --pull web
TARGET_IMAGE="$(docker image inspect "n45-itflow:$TARGET_SHA" --format '{{.Id}}')"
test -n "$TARGET_IMAGE"
TARGET_IMAGE_BUILT=1
echo "TARGET_IMAGE=$TARGET_IMAGE"

PHASE=database-update
echo '=== DATABASE UPDATE ==='
DB_MUTATED=1
dc run --rm --no-deps --user www-data web php scripts/update_cli.php --update_db
dc run --rm --no-deps --user www-data web php scripts/update_cli.php --update_db

reconcile() {
    local script="$1"
    echo "=== $script: DRY RUN ==="
    dc run --rm --no-deps --user www-data web php "$script" --dry-run
    echo "=== $script: APPLY ==="
    dc run --rm --no-deps --user www-data web php "$script" --apply
    echo "=== $script: FINAL DRY RUN ==="
    dc run --rm --no-deps --user www-data web php "$script" --dry-run
}

PHASE=reconciliation
reconcile deploy/psa/reconcile_templates.php
reconcile deploy/psa/reconcile_documentation_requirements.php
reconcile deploy/psa/reconcile_ticket_operations.php
reconcile deploy/psa/reconcile_endpoint_records.php

PHASE=web-canary
echo '=== WEB CANARY ==='
dc up -d --no-deps --force-recreate web
wait_for_web
LOCAL_HTTP="$(http_status http://127.0.0.1:8088/)"
PUBLIC_HTTP="$(http_status "$PUBLIC_URL")"
http_is_healthy "$LOCAL_HTTP"
http_is_healthy "$PUBLIC_HTTP"
echo "CANARY_LOCAL_HTTP=$LOCAL_HTTP"
echo "CANARY_PUBLIC_HTTP=$PUBLIC_HTTP"

PHASE=activation
echo '=== RESTORE PRODUCTION FEATURES ==='
set_env N45_FEATURE_LEVEL "$PREVIOUS_LEVEL"
set_env N45_FEATURE_AUTOMATION "$PREVIOUS_AUTOMATION"
services=(web)
if [ "$PREVIOUS_CRON_RUNNING" -eq 1 ]; then
    services+=(cron)
fi
dc up -d --no-deps --force-recreate "${services[@]}"
wait_for_web

if [ "$PREVIOUS_CRON_RUNNING" -eq 1 ]; then
    test -n "$(dc ps --status running -q cron)"
    sleep "${N45_DEPLOY_CRON_OBSERVE_SECONDS:-75}"
    dc logs --since 2m cron | tee "$EVIDENCE/cron-cycle.log"
    if grep -Eq 'Cron: job .* failed|PHP Fatal error|Uncaught (Error|Exception)' "$EVIDENCE/cron-cycle.log"; then
        echo "The controlled cron cycle reported a fatal job failure." >&2
        false
    fi
fi

PHASE=final-health
FINAL_LOCAL_HTTP="$(http_status http://127.0.0.1:8088/)"
FINAL_PUBLIC_HTTP="$(http_status "$PUBLIC_URL")"
http_is_healthy "$FINAL_LOCAL_HTTP"
http_is_healthy "$FINAL_PUBLIC_HTTP"
test "$(web_health)" = healthy
test "$(sudo -u "$REPO_OWNER" git -c safe.directory="$APP" -C "$APP" rev-parse HEAD)" = "$TARGET_SHA"

printf '%s\n' "$TARGET_SHA" > "$DEPLOYED_MARKER.tmp"
chown root:root "$DEPLOYED_MARKER.tmp"
chmod 0640 "$DEPLOYED_MARKER.tmp"
mv "$DEPLOYED_MARKER.tmp" "$DEPLOYED_MARKER"
WRITERS_STOPPED=0
trap - ERR

echo '=== DEPLOYMENT COMPLETE ==='
echo "HOST_HEAD=$TARGET_SHA"
echo "IMAGE_TAG=$TARGET_SHA"
echo "WEB_IMAGE=$(docker inspect --format '{{.Config.Image}} {{.Image}}' "$(dc ps -q web)")"
echo "WEB_HEALTH=$(web_health)"
echo "LOCAL_HTTP=$FINAL_LOCAL_HTTP"
echo "PUBLIC_HTTP=$FINAL_PUBLIC_HTTP"
echo "CRON_RUNNING=$(dc ps --status running -q cron | wc -l)"
grep -E '^N45_FEATURE_(LEVEL|AUTOMATION)=' "$ENV_FILE"
echo "EVIDENCE=$EVIDENCE"
