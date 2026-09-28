#!/usr/bin/env bash
set -Eeuo pipefail

dispatcher_pids=()
timer_pid=''

stop_children() {
    trap - TERM INT
    if [[ -n "$timer_pid" ]]; then
        kill "$timer_pid" 2>/dev/null || true
    fi
    for pid in "${dispatcher_pids[@]}"; do
        kill "$pid" 2>/dev/null || true
    done
    wait 2>/dev/null || true
    exit 0
}

reap_dispatchers() {
    local running=()
    local pid
    for pid in "${dispatcher_pids[@]}"; do
        if kill -0 "$pid" 2>/dev/null; then
            running+=("$pid")
        else
            wait "$pid" 2>/dev/null || true
        fi
    done
    dispatcher_pids=("${running[@]}")
}

trap stop_children TERM INT

while true; do
    php /var/www/html/cron/cron.php &
    dispatcher_pids+=("$!")

    sleep 60 &
    timer_pid="$!"
    wait "$timer_pid" || true
    timer_pid=''

    reap_dispatchers
done
