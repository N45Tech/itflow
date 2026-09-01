#!/usr/bin/env bash

set -euo pipefail

base_ref="${1:-upstream/master}"
head_ref="${2:-HEAD}"
output_path="${3:-}"
reviewed_base_sha="${4:-${N45_REVIEWED_BASE_SHA:-}}"
reviewed_head_sha="${5:-${N45_REVIEWED_HEAD_SHA:-}}"

if ! git rev-parse --verify --quiet "${base_ref}^{commit}" >/dev/null; then
    echo "Base ref is not available: ${base_ref}" >&2
    echo "Fetch upstream first, for example: git fetch upstream master" >&2
    exit 2
fi
if ! git rev-parse --verify --quiet "${head_ref}^{commit}" >/dev/null; then
    echo "Head ref is not available: ${head_ref}" >&2
    exit 2
fi

base_sha="$(git rev-parse "${base_ref}^{commit}")"
head_sha="$(git rev-parse "${head_ref}^{commit}")"
merge_base="$(git merge-base "$base_sha" "$head_sha")"
read -r behind ahead < <(git rev-list --left-right --count "$base_sha...$head_sha")

review_tmp="$(mktemp -d)"
trap 'rm -rf -- "$review_tmp"' EXIT

git diff --name-only "$merge_base" "$base_sha" | sort -u > "$review_tmp/upstream-paths"
git diff --name-only "$merge_base" "$head_sha" | sort -u > "$review_tmp/fork-paths"
comm -12 "$review_tmp/upstream-paths" "$review_tmp/fork-paths" > "$review_tmp/overlap-paths"

security_rules="${N45_SECURITY_SENSITIVE_PATHS:-n45/security-sensitive-paths.regex}"
if [[ ! -f "$security_rules" ]]; then
    echo "Security-sensitive path rules are missing: $security_rules" >&2
    exit 2
fi
grep -Ev '^[[:space:]]*(#|$)' "$security_rules" > "$review_tmp/security-rules" || true
if [[ ! -s "$review_tmp/security-rules" ]]; then
    echo "Security-sensitive path rules are empty: $security_rules" >&2
    exit 2
fi
if grep -E -f "$review_tmp/security-rules" /dev/null >/dev/null 2>&1; then
    :
else
    security_rule_status=$?
    if [[ "$security_rule_status" -eq 2 ]]; then
        echo "Security-sensitive path rules are invalid: $security_rules" >&2
        exit 2
    fi
fi
grep -E -f "$review_tmp/security-rules" "$review_tmp/overlap-paths" > "$review_tmp/sensitive-overlap-paths" || true

changed_files="$(wc -l < "$review_tmp/fork-paths" | tr -d ' ')"
upstream_changed_files="$(wc -l < "$review_tmp/upstream-paths" | tr -d ' ')"
overlap_files="$(wc -l < "$review_tmp/overlap-paths" | tr -d ' ')"
sensitive_overlap_files="$(wc -l < "$review_tmp/sensitive-overlap-paths" | tr -d ' ')"

git diff --name-status "$merge_base" "$head_sha" > "$review_tmp/status"
added_files="$(awk '$1 ~ /^A/ {count++} END {print count+0}' "$review_tmp/status")"
modified_files="$(awk '$1 ~ /^M/ {count++} END {print count+0}' "$review_tmp/status")"
deleted_files="$(awk '$1 ~ /^D/ {count++} END {print count+0}' "$review_tmp/status")"
renamed_files="$(awk '$1 ~ /^R/ {count++} END {print count+0}' "$review_tmp/status")"

git diff --check "$merge_base" "$head_sha" > "$review_tmp/diff-check-raw" 2>&1 || true
allowlist_path="${N45_DIFF_CHECK_ALLOWLIST:-n45/upstream-diff-check.allowlist}"
if [[ -f "$allowlist_path" ]]; then
    grep -Fxf "$allowlist_path" "$review_tmp/diff-check-raw" > "$review_tmp/diff-check-known" || true
    grep -Fvxf "$allowlist_path" "$review_tmp/diff-check-raw" > "$review_tmp/diff-check" || true
    grep -Fvxf "$review_tmp/diff-check-raw" "$allowlist_path" > "$review_tmp/diff-check-stale" || true
else
    : > "$review_tmp/diff-check-known"
    : > "$review_tmp/diff-check-stale"
    cp "$review_tmp/diff-check-raw" "$review_tmp/diff-check"
fi
known_diff_issues="$(wc -l < "$review_tmp/diff-check-known" | tr -d ' ')"
stale_diff_exceptions="$(wc -l < "$review_tmp/diff-check-stale" | tr -d ' ')"
if [[ ! -s "$review_tmp/diff-check" && ! -s "$review_tmp/diff-check-stale" ]]; then
    diff_check="Pass"
else
    diff_check="Fail"
fi

if [[ "$sensitive_overlap_files" -eq 0 ]]; then
    sha_review_gate="Not required"
elif [[ "$reviewed_base_sha" == "$base_sha" && "$reviewed_head_sha" == "$head_sha" ]]; then
    sha_review_gate="Pass"
else
    sha_review_gate="Fail"
fi

git diff --diff-filter=ACMR --name-only "$merge_base" "$head_sha" > "$review_tmp/lint-candidates"
lint_failure=0

run_lint() {
    local label="$1"
    local command_name="$2"
    local extension_pattern="$3"
    local result_file="$4"
    local candidates_file="$review_tmp/lint-${label}-files"

    grep -E "$extension_pattern" "$review_tmp/lint-candidates" > "$candidates_file" || true
    if [[ ! -s "$candidates_file" ]]; then
        printf '%s' 'No matching changes' > "$result_file"
        return
    fi
    if ! command -v "$command_name" >/dev/null 2>&1; then
        printf '%s' 'Unavailable' > "$result_file"
        return
    fi

    local failed=0
    while IFS= read -r lint_file; do
        [[ -f "$lint_file" ]] || continue
        : > "$review_tmp/lint-one"
        local file_failed=0
        case "$command_name" in
            php) php -l "$lint_file" > "$review_tmp/lint-one" 2>&1 || file_failed=1 ;;
            node) node --check "$lint_file" > "$review_tmp/lint-one" 2>&1 || file_failed=1 ;;
            bash) bash -n "$lint_file" > "$review_tmp/lint-one" 2>&1 || file_failed=1 ;;
        esac
        if [[ "$file_failed" -ne 0 ]]; then
            failed=1
            echo "$lint_file:" >> "$review_tmp/lint-output"
            cat "$review_tmp/lint-one" >> "$review_tmp/lint-output"
        fi
    done < "$candidates_file"
    if [[ "$failed" -eq 0 ]]; then
        printf '%s' 'Pass' > "$result_file"
    else
        printf '%s' 'Fail' > "$result_file"
        lint_failure=1
    fi
}

: > "$review_tmp/lint-output"
run_lint php php '\.php$' "$review_tmp/php-lint-result"
run_lint javascript node '\.(js|mjs)$' "$review_tmp/javascript-lint-result"
run_lint shell bash '\.sh$' "$review_tmp/shell-lint-result"
php_lint="$(cat "$review_tmp/php-lint-result")"
javascript_lint="$(cat "$review_tmp/javascript-lint-result")"
shell_lint="$(cat "$review_tmp/shell-lint-result")"

report_file="$review_tmp/report.md"
{
    echo "# N45 upstream parity report"
    echo
    echo "Generated: $(date -u +'%Y-%m-%dT%H:%M:%SZ')"
    echo
    echo "| Measure | Value |"
    echo "| --- | --- |"
    echo "| Base | \`$base_ref\` (\`$base_sha\`) |"
    echo "| Head | \`$head_ref\` (\`$head_sha\`) |"
    echo "| Merge base | \`$merge_base\` |"
    echo "| Head ahead / behind base | $ahead / $behind |"
    echo "| Fork changed files | $changed_files |"
    echo "| Added / modified / deleted / renamed | $added_files / $modified_files / $deleted_files / $renamed_files |"
    echo "| Upstream files changed since merge base | $upstream_changed_files |"
    echo "| Paths changed by both sides | $overlap_files |"
    echo "| Security-sensitive overlaps | $sensitive_overlap_files |"
    echo "| Reviewed SHA gate | $sha_review_gate |"
    echo "| Diff whitespace check | $diff_check |"
    echo "| Exact historical whitespace exceptions | $known_diff_issues |"
    echo "| Stale whitespace exceptions | $stale_diff_exceptions |"
    echo "| PHP lint | $php_lint |"
    echo "| JavaScript syntax | $javascript_lint |"
    echo "| Shell syntax | $shell_lint |"
    echo
    echo "## Changed-file buckets"
    echo
    echo "| Files | Top-level path |"
    echo "| ---: | --- |"
    awk -F/ '{bucket=(NF == 1 ? "(root)" : $1); count[bucket]++} END {for (bucket in count) print count[bucket], bucket}' "$review_tmp/fork-paths" \
        | sort -k1,1nr -k2,2 \
        | awk '{count=$1; $1=""; sub(/^ /, ""); printf "| %s | `%s` |\n", count, $0}'
    echo
    echo "## Security-sensitive upstream overlaps"
    echo
    if [[ -s "$review_tmp/sensitive-overlap-paths" ]]; then
        sed 's#^#- `#; s#$#`#' "$review_tmp/sensitive-overlap-paths"
        echo
        echo "Approval must provide these exact commits: base \`$base_sha\`, head \`$head_sha\`."
        if [[ "$sha_review_gate" == "Fail" ]]; then
            echo "The supplied reviewed SHAs were absent or did not match; integration is blocked."
        fi
    else
        echo "No security-sensitive paths were changed by both upstream and the fork."
    fi
    echo
    echo "## All upstream overlap candidates"
    echo
    if [[ -s "$review_tmp/overlap-paths" ]]; then
        sed 's#^#- `#; s#$#`#' "$review_tmp/overlap-paths"
    else
        echo "No paths were changed by both upstream and the fork since their merge base."
    fi
    echo
    echo "## Largest fork diffs"
    echo
    echo "| Lines changed | Added | Deleted | Path |"
    echo "| ---: | ---: | ---: | --- |"
    git diff --numstat "$merge_base" "$head_sha" \
        | awk '$1 ~ /^[0-9]+$/ && $2 ~ /^[0-9]+$/ {print $1+$2, $1, $2, $3}' \
        | sort -k1,1nr -k4,4 \
        | awk 'NR <= 20 {printf "| %s | %s | %s | `%s` |\n", $1, $2, $3, $4}'
    echo
    echo "## N45 migration files changed by the fork"
    echo
    migration_count=0
    while IFS= read -r migration_path; do
        case "$migration_path" in
            n45/migrations/*.php)
                echo "- \`$migration_path\`"
                migration_count=$((migration_count + 1))
                ;;
        esac
    done < "$review_tmp/fork-paths"
    if [[ "$migration_count" -eq 0 ]]; then
        echo "No N45 migration files changed."
    fi
    echo
    echo "## Review result"
    echo
    if [[ -s "$review_tmp/diff-check-known" ]]; then
        echo "Known baseline exceptions (exact-match allowlist):"
        echo
        sed 's/^/- /' "$review_tmp/diff-check-known"
        echo
    fi
    if [[ -s "$review_tmp/diff-check-stale" ]]; then
        echo "Stale allowlist entries (remove or update them):"
        echo
        sed 's/^/- /' "$review_tmp/diff-check-stale"
        echo
    fi
    if [[ -s "$review_tmp/lint-output" ]]; then
        echo "Available lint output:"
        echo
        sed 's/^/- /' "$review_tmp/lint-output"
        echo
    fi
    if [[ "$diff_check" == "Pass" && "$sha_review_gate" != "Fail" && "$lint_failure" -eq 0 ]]; then
        echo "Parity gates passed. Review the reported overlap candidates and database canaries before integration."
    else
        echo "Parity gates failed. Resolve whitespace/lint errors or approve the exact sensitive-overlap SHAs before integration."
        if [[ -s "$review_tmp/diff-check" ]]; then
            sed 's/^/- /' "$review_tmp/diff-check"
        fi
    fi
} > "$report_file"

cat "$report_file"
if [[ -n "$output_path" ]]; then
    cp "$report_file" "$output_path"
fi

[[ "$diff_check" == "Pass" && "$sha_review_gate" != "Fail" && "$lint_failure" -eq 0 ]]
