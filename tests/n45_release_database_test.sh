#!/usr/bin/env bash

set -Eeuo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"

# This is the reviewed, clean ITFlow ancestor documented in
# docs/n45/upstream-parity.md. Its highest upstream migration is 2.6.7.
UPSTREAM_SCHEMA_COMMIT=0262707ef029ffb294df197e10d9b918adb0c85d
FINAL_DATABASE=n45_ci_final
UPGRADE_DATABASE=n45_ci_upgrade
LEGACY_DATABASE=n45_ci_legacy

fail() {
    echo "N45 release database test failed: $*" >&2
    exit 1
}

for command_name in git mariadb mariadb-dump php; do
    command -v "$command_name" > /dev/null || fail "missing required command: $command_name"
done
for environment_name in N45_CI_DB_HOST N45_CI_DB_USER N45_CI_DB_PASSWORD; do
    [[ -n "${!environment_name:-}" ]] || fail "missing required environment variable: $environment_name"
done

[[ ! -e config.php && ! -L config.php ]] || fail 'refusing to replace an existing config.php'
ln -s tests/fixtures/n45_release_config.php config.php

TEMP_DIRECTORY=$(mktemp -d)
cleanup() {
    rm -f config.php
    rm -rf "$TEMP_DIRECTORY"
}
trap cleanup EXIT

export MYSQL_PWD="$N45_CI_DB_PASSWORD"
DATABASE_CLIENT=(
    mariadb
    --protocol=TCP
    --host="$N45_CI_DB_HOST"
    --port="${N45_CI_DB_PORT:-3306}"
    --user="$N45_CI_DB_USER"
    --batch
    --skip-column-names
)

reset_database() {
    local database_name=$1
    [[ "$database_name" =~ ^[a-z0-9_]+$ ]] || fail "unsafe database name: $database_name"
    "${DATABASE_CLIENT[@]}" -e "DROP DATABASE IF EXISTS \`$database_name\`; CREATE DATABASE \`$database_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
}

import_database() {
    local database_name=$1
    local sql_file=$2
    "${DATABASE_CLIENT[@]}" "$database_name" < "$sql_file"
}

seed_marker() {
    local database_name=$1
    local marker=$2
    [[ "$marker" =~ ^[0-9]+(\.[0-9]+)+$ ]] || fail "unsafe database marker: $marker"
    "${DATABASE_CLIENT[@]}" "$database_name" -e "INSERT INTO settings (company_id, config_current_database_version) VALUES (1, '$marker');"
}

run_update() {
    local database_name=$1
    local log_file=$2
    export N45_CI_DB_NAME="$database_name"
    php scripts/update_cli.php --update_db 2>&1 | tee "$log_file"
}

assert_current() {
    local database_name=$1
    export N45_CI_DB_NAME="$database_name"
    php tests/n45_release_database_assert.php runner
}

UPSTREAM_MARKER=$(php -r '$manifest = require "n45/manifest.php"; echo $manifest["maintenance"]["upstream_marker_base"] ?? "";')
[[ "$UPSTREAM_MARKER" == '2.6.7' ]] || fail "unexpected upstream marker base: $UPSTREAM_MARKER"
git cat-file -e "$UPSTREAM_SCHEMA_COMMIT^{commit}" || fail 'reviewed upstream schema commit is unavailable'
git merge-base --is-ancestor "$UPSTREAM_SCHEMA_COMMIT" HEAD || fail 'reviewed upstream schema commit is not an ancestor of this release'
git show "$UPSTREAM_SCHEMA_COMMIT:db.sql" > "$TEMP_DIRECTORY/upstream-2.6.7.sql"

UPSTREAM_LATEST=$(
    git ls-tree -r --name-only "$UPSTREAM_SCHEMA_COMMIT" admin/database_updates \
        | sed -n 's#^admin/database_updates/\([0-9][0-9.]*\)\.php$#\1#p' \
        | sort -V \
        | tail -n 1
)
[[ "$UPSTREAM_LATEST" == "$UPSTREAM_MARKER" ]] || fail "reviewed schema ends at $UPSTREAM_LATEST instead of $UPSTREAM_MARKER"

echo 'Importing and validating the final db.sql snapshot'
reset_database "$FINAL_DATABASE"
import_database "$FINAL_DATABASE" db.sql
FINAL_TABLE_COUNT=$("${DATABASE_CLIENT[@]}" "$FINAL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();")
[[ "$FINAL_TABLE_COUNT" -gt 0 ]] || fail 'final db.sql imported no tables'
seed_marker "$FINAL_DATABASE" "$UPSTREAM_MARKER"
run_update "$FINAL_DATABASE" "$TEMP_DIRECTORY/final-update.log"
assert_current "$FINAL_DATABASE"
php tests/n45_transaction_state_database_assert.php

echo 'Exercising the documentation evaluator against MariaDB'
"${DATABASE_CLIENT[@]}" "$FINAL_DATABASE" -e "UPDATE settings SET config_enable_cron = 1 WHERE company_id = 1;"
export N45_CI_DB_NAME="$FINAL_DATABASE"
php cron/documentation_evaluator.php 2>&1 | tee "$TEMP_DIRECTORY/documentation-evaluator.log"
grep -Fq 'failed 0.' "$TEMP_DIRECTORY/documentation-evaluator.log" \
    || fail 'the documentation evaluator did not complete cleanly'

echo 'Upgrading the clean upstream 2.6.7 schema through the production CLI'
reset_database "$UPGRADE_DATABASE"
import_database "$UPGRADE_DATABASE" "$TEMP_DIRECTORY/upstream-2.6.7.sql"
UPSTREAM_LEDGER_COUNT=$("${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'n45_schema_migrations';")
[[ "$UPSTREAM_LEDGER_COUNT" == '0' ]] || fail 'reviewed upstream schema unexpectedly contains the N45 ledger'
seed_marker "$UPGRADE_DATABASE" "$UPSTREAM_MARKER"
run_update "$UPGRADE_DATABASE" "$TEMP_DIRECTORY/upgrade.log"
assert_current "$UPGRADE_DATABASE"

echo 'Exercising append-only retention events and exact trigger drift refusal'
RETENTION_EVENT_ID=$("${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "INSERT INTO retention_events
    (retention_event_record_type, retention_event_record_id, retention_event_client_id,
     retention_event_generation, retention_event_action, retention_event_actor_type,
     retention_event_actor_id, retention_event_reason, retention_event_metadata,
     retention_event_metadata_hash, retention_event_batch_id)
    VALUES ('release-test', 1, 0, 0, 'created', 'system', 0, 'Trigger contract',
        '{}', SHA2('{}',256), 0); SELECT LAST_INSERT_ID();")
[[ "$RETENTION_EVENT_ID" =~ ^[1-9][0-9]*$ ]] || fail 'could not create the retention trigger fixture'
set +e
"${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "UPDATE retention_events SET retention_event_reason = 'mutated' WHERE retention_event_id = $RETENTION_EVENT_ID;" > "$TEMP_DIRECTORY/retention-update.log" 2>&1
RETENTION_UPDATE_STATUS=$?
"${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "DELETE FROM retention_events WHERE retention_event_id = $RETENTION_EVENT_ID;" > "$TEMP_DIRECTORY/retention-delete.log" 2>&1
RETENTION_DELETE_STATUS=$?
set -e
[[ "$RETENTION_UPDATE_STATUS" -ne 0 ]] || fail 'the retention UPDATE trigger allowed immutable history to change'
[[ "$RETENTION_DELETE_STATUS" -ne 0 ]] || fail 'the retention DELETE trigger allowed immutable history to disappear'

"${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "DROP TRIGGER retention_events_no_update;
    CREATE TRIGGER retention_events_no_update BEFORE UPDATE ON retention_events FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wrong retention trigger';"
export N45_CI_DB_NAME="$UPGRADE_DATABASE"
set +e
php tests/n45_release_database_assert.php runner > "$TEMP_DIRECTORY/retention-trigger-drift.log" 2>&1
RETENTION_DRIFT_STATUS=$?
set -e
[[ "$RETENTION_DRIFT_STATUS" -ne 0 ]] || fail 'an unexpected same-name retention trigger passed release validation'
grep -Fq 'retention event UPDATE immutability trigger is missing or drifted' "$TEMP_DIRECTORY/retention-trigger-drift.log" \
    || fail 'retention trigger drift was not identified precisely'
"${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "DROP TRIGGER retention_events_no_update;
    CREATE TRIGGER retention_events_no_update BEFORE UPDATE ON retention_events FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'retention_events is append-only';"
assert_current "$UPGRADE_DATABASE"

echo 'Exercising n45-0012 historical index repair and unexpected-drift refusal'
HISTORICAL_SNAPSHOT_INDEX='automation_snapshot_source,automation_snapshot_entity_type,automation_snapshot_external_id,automation_snapshot_payload_hash'
FINAL_SNAPSHOT_INDEX='automation_snapshot_source,automation_snapshot_entity_type,automation_snapshot_external_id,automation_snapshot_client_id,automation_snapshot_asset_id,automation_snapshot_payload_hash'
UNEXPECTED_SNAPSHOT_INDEX='automation_snapshot_source,automation_snapshot_entity_type,automation_snapshot_external_id,automation_snapshot_client_id,automation_snapshot_payload_hash'
"${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "ALTER TABLE automation_entity_snapshots
    DROP INDEX automation_snapshot_source_entity_hash,
    ADD UNIQUE KEY automation_snapshot_source_entity_hash
        (automation_snapshot_source, automation_snapshot_entity_type,
         automation_snapshot_external_id, automation_snapshot_payload_hash);"
HISTORICAL_INDEX_SHAPE=$("${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "SELECT CONCAT(MAX(NON_UNIQUE), '|', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), '|', SUM(SUB_PART IS NOT NULL)) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'automation_entity_snapshots' AND INDEX_NAME = 'automation_snapshot_source_entity_hash';")
[[ "$HISTORICAL_INDEX_SHAPE" == "0|$HISTORICAL_SNAPSHOT_INDEX|0" ]] || fail 'the historical endpoint compatibility fixture has the wrong source shape'
export N45_CI_DB_NAME="$UPGRADE_DATABASE"
php tests/fixtures/run_n45_endpoint_migration.php > "$TEMP_DIRECTORY/endpoint-historical-repair.log" 2>&1
REPAIRED_INDEX_SHAPE=$("${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "SELECT CONCAT(MAX(NON_UNIQUE), '|', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), '|', SUM(SUB_PART IS NOT NULL)) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'automation_entity_snapshots' AND INDEX_NAME = 'automation_snapshot_source_entity_hash';")
[[ "$REPAIRED_INDEX_SHAPE" == "0|$FINAL_SNAPSHOT_INDEX|0" ]] || fail 'n45-0012 did not normalize the exact historical snapshot index'

"${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "ALTER TABLE automation_entity_snapshots
    DROP INDEX automation_snapshot_source_entity_hash,
    ADD UNIQUE KEY automation_snapshot_source_entity_hash
        (automation_snapshot_source, automation_snapshot_entity_type,
         automation_snapshot_external_id, automation_snapshot_client_id,
         automation_snapshot_payload_hash);"
set +e
php tests/fixtures/run_n45_endpoint_migration.php > "$TEMP_DIRECTORY/endpoint-unexpected-index.log" 2>&1
UNEXPECTED_INDEX_STATUS=$?
set -e
[[ "$UNEXPECTED_INDEX_STATUS" -ne 0 ]] || fail 'n45-0012 accepted an unexpected snapshot index shape'
grep -Fq 'Unexpected identity snapshot uniqueness shape; refusing destructive repair' "$TEMP_DIRECTORY/endpoint-unexpected-index.log" || fail 'n45-0012 did not identify unexpected snapshot-index drift'
PRESERVED_INDEX_SHAPE=$("${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "SELECT CONCAT(MAX(NON_UNIQUE), '|', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','), '|', SUM(SUB_PART IS NOT NULL)) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'automation_entity_snapshots' AND INDEX_NAME = 'automation_snapshot_source_entity_hash';")
[[ "$PRESERVED_INDEX_SHAPE" == "0|$UNEXPECTED_SNAPSHOT_INDEX|0" ]] || fail 'n45-0012 mutated the unexpected snapshot index before refusing it'
"${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "ALTER TABLE automation_entity_snapshots
    DROP INDEX automation_snapshot_source_entity_hash,
    ADD UNIQUE KEY automation_snapshot_source_entity_hash
        (automation_snapshot_source, automation_snapshot_entity_type,
         automation_snapshot_external_id, automation_snapshot_client_id,
         automation_snapshot_asset_id, automation_snapshot_payload_hash);"
assert_current "$UPGRADE_DATABASE"

echo 'Exercising the explicit legacy-marker bridge against a schema-complete fixture'
LEGACY_MARKER=$(php -r '
    $manifest = require "n45/manifest.php";
    $latest = null;
    foreach (($manifest["migrations"] ?? []) as $migration) {
        $version = $migration["legacy_version"] ?? null;
        if (is_string($version) && $version !== "" && ($latest === null || version_compare($version, $latest, ">"))) {
            $latest = $version;
        }
    }
    echo $latest ?? "";
')
[[ "$LEGACY_MARKER" =~ ^[0-9]+(\.[0-9]+)+$ ]] || fail 'the manifest has no safe legacy bridge marker'

mariadb-dump \
    --protocol=TCP \
    --host="$N45_CI_DB_HOST" \
    --port="${N45_CI_DB_PORT:-3306}" \
    --user="$N45_CI_DB_USER" \
    --single-transaction \
    --skip-comments \
    --skip-lock-tables \
    "$UPGRADE_DATABASE" > "$TEMP_DIRECTORY/legacy-fixture.sql"
reset_database "$LEGACY_DATABASE"
import_database "$LEGACY_DATABASE" "$TEMP_DIRECTORY/legacy-fixture.sql"
# Reconstruct the one schema difference in the historical numeric 2.7.8
# release. The explicit bridge must accept only this exact unique shape, and
# n45-0015 must subsequently normalize it back to the final non-unique index.
"${DATABASE_CLIENT[@]}" "$LEGACY_DATABASE" -e "ALTER TABLE documentation_evidence_locker
    DROP INDEX documentation_evidence_reference,
    ADD UNIQUE INDEX documentation_evidence_reference (
        documentation_evidence_obligation_id,
        documentation_evidence_requirement_version_id,
        documentation_evidence_reference_type,
        documentation_evidence_reference_id,
        documentation_evidence_reference_hash
    );"
"${DATABASE_CLIENT[@]}" "$LEGACY_DATABASE" -e "DELETE FROM n45_schema_migrations; UPDATE settings SET config_current_database_version = '$LEGACY_MARKER' WHERE company_id = 1;"

export N45_CI_DB_NAME="$LEGACY_DATABASE"
php scripts/update_cli.php --bridge_n45_migrations 2>&1 | tee "$TEMP_DIRECTORY/legacy-bridge.log"
grep -Fq 'Legacy N45 migration bridge completed.' "$TEMP_DIRECTORY/legacy-bridge.log" || fail 'the explicit legacy bridge did not complete'
run_update "$LEGACY_DATABASE" "$TEMP_DIRECTORY/legacy-update.log"
php tests/n45_release_database_assert.php legacy
LEGACY_REPAIRED_NON_UNIQUE=$("${DATABASE_CLIENT[@]}" "$LEGACY_DATABASE" -e "SELECT NON_UNIQUE
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'documentation_evidence_locker'
      AND index_name = 'documentation_evidence_reference'
    ORDER BY SEQ_IN_INDEX LIMIT 1;")
[[ "$LEGACY_REPAIRED_NON_UNIQUE" == '1' ]] || fail 'n45-0015 did not restore the final non-unique evidence-reference index'

set +e
php scripts/update_cli.php --bridge_n45_migrations > "$TEMP_DIRECTORY/legacy-repeat.log" 2>&1
LEGACY_REPEAT_STATUS=$?
set -e
[[ "$LEGACY_REPEAT_STATUS" -ne 0 ]] || fail 'a completed legacy bridge ran a second time'
grep -Fq 'The database marker does not require the legacy N45 bridge' "$TEMP_DIRECTORY/legacy-repeat.log" || fail 'the repeated legacy bridge did not fail closed for the expected reason'

echo 'Replaying the last migration after its durable ledger write is removed'
LAST_MIGRATION_ID=$(php -r '$manifest = require "n45/manifest.php"; echo array_key_last($manifest["migrations"] ?? []);')
[[ "$LAST_MIGRATION_ID" =~ ^n45-[0-9]{4}-[a-z0-9-]+$ ]] || fail "unsafe last migration ID: $LAST_MIGRATION_ID"
"${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "DELETE FROM n45_schema_migrations WHERE migration_id = '$LAST_MIGRATION_ID';"
run_update "$UPGRADE_DATABASE" "$TEMP_DIRECTORY/retry.log"
grep -Fq "Applied N45 database migration $LAST_MIGRATION_ID" "$TEMP_DIRECTORY/retry.log" || fail 'the retry did not replay the missing ledger migration'
assert_current "$UPGRADE_DATABASE"

echo 'Confirming a fully current database is a durable no-op'
LEDGER_BEFORE=$("${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "SELECT CONCAT_WS('|', migration_id, migration_checksum, COALESCE(migration_legacy_version, ''), migration_applied_by, migration_applied_at) FROM n45_schema_migrations ORDER BY migration_id;")
run_update "$UPGRADE_DATABASE" "$TEMP_DIRECTORY/no-op.log"
LEDGER_AFTER=$("${DATABASE_CLIENT[@]}" "$UPGRADE_DATABASE" -e "SELECT CONCAT_WS('|', migration_id, migration_checksum, COALESCE(migration_legacy_version, ''), migration_applied_by, migration_applied_at) FROM n45_schema_migrations ORDER BY migration_id;")
[[ "$LEDGER_AFTER" == "$LEDGER_BEFORE" ]] || fail 'the no-op update changed the migration ledger'
! grep -Fq 'Applied N45 database migration' "$TEMP_DIRECTORY/no-op.log" || fail 'the no-op update replayed an N45 migration'
grep -Fq "Database is already at the latest version ($UPSTREAM_MARKER). No updates were applied." "$TEMP_DIRECTORY/no-op.log" || fail 'the CLI did not report a no-op update'
assert_current "$UPGRADE_DATABASE"

echo "N45 release database validation passed ($FINAL_TABLE_COUNT final tables, last migration $LAST_MIGRATION_ID)."
