#!/usr/bin/env bash
set -euo pipefail

DB_HOST="${N45_CI_DB_HOST:-127.0.0.1}"
DB_PORT="${N45_CI_DB_PORT:-3306}"
DB_USER="${N45_CI_DB_USER:-root}"
DB_NAME="n45_lock_order_acceptance"
DB_CLIENT=(mariadb --protocol=tcp --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER"
    --batch --skip-column-names)

cleanup() {
    "${DB_CLIENT[@]}" -e "DROP DATABASE IF EXISTS n45_lock_order_acceptance" >/dev/null
}
trap cleanup EXIT

"${DB_CLIENT[@]}" -e "DROP DATABASE IF EXISTS n45_lock_order_acceptance;
    CREATE DATABASE n45_lock_order_acceptance;
    CREATE TABLE n45_lock_order_acceptance.lock_probe (
        id int NOT NULL PRIMARY KEY,
        touched int NOT NULL DEFAULT 0
    ) ENGINE=InnoDB;
    INSERT INTO n45_lock_order_acceptance.lock_probe (id) VALUES (1),(2);"

first_output="$(mktemp)"
second_output="$(mktemp)"
trap 'rm -f "$first_output" "$second_output"; cleanup' EXIT

"${DB_CLIENT[@]}" n45_lock_order_acceptance >"$first_output" <<'SQL' &
START TRANSACTION;
SELECT id FROM lock_probe WHERE id IN (1,2) ORDER BY id FOR UPDATE;
SELECT GET_LOCK('n45_lock_order_rows_held', 0);
DO SLEEP(2);
UPDATE lock_probe SET touched = touched + 1 WHERE id = 1;
SELECT RELEASE_LOCK('n45_lock_order_rows_held');
COMMIT;
SQL
first_pid=$!

ready=0
for _ in $(seq 1 50); do
    lock_owner="$("${DB_CLIENT[@]}" -e "SELECT COALESCE(IS_USED_LOCK('n45_lock_order_rows_held'), 0)")"
    if [[ "$lock_owner" != "0" ]]; then
        ready=1
        break
    fi
    sleep 0.1
done
[[ "$ready" == "1" ]] || {
    wait "$first_pid" || true
    echo "First ordered transaction did not acquire its probe lock" >&2
    exit 1
}

"${DB_CLIENT[@]}" n45_lock_order_acceptance >"$second_output" <<'SQL'
START TRANSACTION;
SET @started_at = NOW(6);
SELECT id FROM lock_probe WHERE id IN (1,2) ORDER BY id FOR UPDATE;
SELECT TIMESTAMPDIFF(MICROSECOND, @started_at, NOW(6));
UPDATE lock_probe SET touched = touched + 1 WHERE id = 2;
COMMIT;
SQL
wait "$first_pid"

wait_microseconds="$(tail -n 1 "$second_output")"
[[ "$wait_microseconds" =~ ^[0-9]+$ ]] || {
    echo "Could not read ordered-lock wait duration" >&2
    exit 1
}
(( wait_microseconds >= 1000000 )) || {
    echo "Second transaction did not serialize behind the first" >&2
    exit 1
}

touch_count="$("${DB_CLIENT[@]}" n45_lock_order_acceptance -e "SELECT SUM(touched) FROM lock_probe")"
[[ "$touch_count" == "2" ]] || {
    echo "Ordered transactions did not both commit exactly once" >&2
    exit 1
}

echo "N45 lock-order database acceptance passed."
