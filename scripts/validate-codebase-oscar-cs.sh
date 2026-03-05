#!/usr/bin/env bash
# Validate ALL PHP files under SITE_PATH against the Oscar coding standard.
# Intended for local use; not recommended for CI (requires two branch checkouts).
#
# Default: compare BRANCH against PHPCS_BASE_BRANCH (default: master) and report
# only violations that are new in BRANCH (pre-existing violations are subtracted).
# PHPCS_REPORT_ALL_LINES=1: skip the base comparison; report every violation found.
#
# Side effects:
#   - git checkout to BASE branch, then to BRANCH (both restored on exit).
#   - Writes a timestamped report file to PHPCS_REPORT_PATH (default: current dir).
#   - If uncommitted changes are present, prompts to stash them (restored on exit).
#   - No source files are modified (read-only analysis).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-oscar-cs.sh
source "${SCRIPT_DIR}/lib-oscar-cs.sh"
check_bash_version

usage() {
    cat >&2 << 'EOF'
Usage: validate-codebase-oscar-cs.sh SITE_PATH [BRANCH]

  SITE_PATH           Path to the PHP project to scan (e.g. infra/sites/hejoscar.dk, or '.' when inside it).
                      phpcs and the Oscar ruleset are resolved from the script's own location (oscar-cs/).
  BRANCH              Branch to scan (default: current git HEAD).
  PHPCS_BASE_BRANCH   Base branch for comparison (default: master).

  Default behaviour:  Run phpcs on BRANCH and on PHPCS_BASE_BRANCH, subtract violations
                      already present in base, and report only new violations.
  PHPCS_REPORT_ALL_LINES=1  Skip the base comparison; report every violation in BRANCH.

  Report directory: PHPCS_REPORT_PATH (default: current working directory).
  Report filename (new only):  phpcs-report-codebase-<BRANCH>-vs-<BASE>-YYYYMMDD-HHMMSS.txt
  Report filename (all):       phpcs-report-codebase-<BRANCH>-YYYYMMDD-HHMMSS.txt
  Override full path: PHPCS_REPORT_FILE (takes precedence over PHPCS_REPORT_PATH).
EOF
}

# ── arguments ─────────────────────────────────────────────────────────────────
if [[ -z "${1:-}" ]]; then
    echo "Error: SITE_PATH is required." >&2
    usage
    exit 1
fi
SITE_ARG="$1"
BRANCH="${2:-}"
BASE_BRANCH="${PHPCS_BASE_BRANCH:-master}"

# ── setup ─────────────────────────────────────────────────────────────────────
setup_site "${SITE_ARG}"
setup_tools
check_prerequisites

[[ -z "${BRANCH}" ]] && BRANCH="$(git -C "${GIT_ROOT}" rev-parse --abbrev-ref HEAD)"
BRANCH_REF="$(resolve_ref "${BRANCH}")"
BASE_REF="$(resolve_ref "${BASE_BRANCH}")"

BRANCH_SAFE="$(sanitize_branch "${BRANCH}")"
BASE_SAFE="$(sanitize_branch "${BASE_BRANCH}")"
[[ -z "${BRANCH_SAFE}" ]] && BRANCH_SAFE="current"
[[ -z "${BASE_SAFE}" ]] && BASE_SAFE="base"

REPORT_DIR="${PHPCS_REPORT_PATH:-${PWD}}"
REPORT_DIR="${REPORT_DIR%/}"
if [[ -n "${PHPCS_REPORT_ALL_LINES:-}" ]]; then
    REPORT_FILE="${PHPCS_REPORT_FILE:-${REPORT_DIR}/phpcs-report-codebase-${BRANCH_SAFE}-$(date +%Y%m%d-%H%M%S).txt}"
else
    REPORT_FILE="${PHPCS_REPORT_FILE:-${REPORT_DIR}/phpcs-report-codebase-${BRANCH_SAFE}-vs-${BASE_SAFE}-$(date +%Y%m%d-%H%M%S).txt}"
fi
check_report_dir "${REPORT_FILE}"

# ── ref validation ─────────────────────────────────────────────────────────────
if ! git -C "${GIT_ROOT}" rev-parse "${BRANCH_REF}" >/dev/null 2>&1; then
    echo "Error: ref '${BRANCH}' (resolved: ${BRANCH_REF}) not found." >&2; exit 1
fi
if [[ -z "${PHPCS_REPORT_ALL_LINES:-}" ]]; then
    if ! git -C "${GIT_ROOT}" rev-parse "${BASE_REF}" >/dev/null 2>&1; then
        echo "Error: base ref '${BASE_BRANCH}' (resolved: ${BASE_REF}) not found." >&2; exit 1
    fi
fi

# ── git safety (stash, cleanup, traps) ────────────────────────────────────────
setup_git_safety "validate-codebase-oscar-cs temporary stash"

# ── phpcs runner ──────────────────────────────────────────────────────────────
run_phpcs() {
    local out="$1"
    (cd "${SITE_FULL}" && "${PHPCS_BIN}" \
        -d memory_limit=512M \
        --standard="${RULESET}" \
        --extensions=php \
        --ignore=*/vendor/*,*/node_modules/*,*/storage/* \
        --report-file="${out}" \
        .) || true
    # phpcs may delete the report file when killed mid-run; recreate it as empty
    # so any code that reads it after an interrupt gets EOF rather than an error.
    touch "${out}" 2>/dev/null || true
}

# ── violation key extraction ──────────────────────────────────────────────────
extract_violation_keys() {
    local report="$1" cur_file=""
    while IFS= read -r line; do
        if [[ "$line" == FILE:* ]]; then
            cur_file="${line#FILE: }"
            cur_file="${cur_file%%[[:space:]]*}"
            [[ "$cur_file" == *"${SITE_NAME}/"* ]] && cur_file="${cur_file#*${SITE_NAME}/}"
        elif [[ "$line" =~ ^[[:space:]]*([0-9]+)[[:space:]]+\|[[:space:]]+(ERROR|WARNING)[[:space:]]+\|[[:space:]]*(.+) ]]; then
            printf '%s:%s:%s:%s\n' "$cur_file" "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "${BASH_REMATCH[3]}"
        fi
    done < "$report"
}

# ── new-violations filter ─────────────────────────────────────────────────────
# Reads current phpcs report ($1), subtracts violation keys from base keys file ($2),
# writes filtered report to stdout. FILE blocks with no remaining violations are suppressed.
# flush_block is provided by lib-oscar-cs.sh (dynamic scoping: accesses buffer, kept_* locals).
filter_new_violations() {
    local current_report="$1"
    local base_keys_file="$2"
    local cur_file="" keep_violation=0 kept_any=0
    local kept_errors=0 kept_warnings=0 kept_fixable=0
    local -A kept_line_nums=()
    local -a buffer=()
    declare -A base_keys=()
    while IFS= read -r key; do
        base_keys["$key"]=1
    done < "$base_keys_file"

    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ "$line" == FILE:* ]]; then
            flush_block
            buffer+=("$line")
            cur_file="${line#FILE: }"
            cur_file="${cur_file%%[[:space:]]*}"
            [[ "$cur_file" == *"${SITE_NAME}/"* ]] && cur_file="${cur_file#*${SITE_NAME}/}"
            keep_violation=0
        elif [[ "$line" =~ ^[[:space:]]*([0-9]+)[[:space:]]+\|[[:space:]]+(ERROR|WARNING)[[:space:]]+\|[[:space:]]*(.+) ]]; then
            local lnum="${BASH_REMATCH[1]}" sev="${BASH_REMATCH[2]}" msg="${BASH_REMATCH[3]}"
            local key="${cur_file}:${lnum}:${sev}:${msg}"
            if [[ -z "${base_keys[$key]+_}" ]]; then
                keep_violation=1; kept_any=1
                if [[ "$sev" == "ERROR" ]]; then ((kept_errors++)) || true; else ((kept_warnings++)) || true; fi
                [[ "$msg" == \[x\]* ]] && ((kept_fixable++)) || true
                kept_line_nums["$lnum"]=1
                buffer+=("$line")
            else
                keep_violation=0
            fi
        elif [[ "$line" =~ ^[[:space:]]*\| ]]; then
            [[ $keep_violation -eq 1 ]] && buffer+=("$line")
        elif [[ "$line" =~ ^-+$ ]] || [[ "$line" =~ ^FOUND ]] || [[ "$line" =~ ^PHPCBF ]] || [[ "$line" =~ ^[[:space:]]*$ ]]; then
            keep_violation=0; buffer+=("$line")
        else
            flush_block; echo "$line"; cur_file=""
        fi
    done < "$current_report"
    flush_block
}

# ── main ──────────────────────────────────────────────────────────────────────
if [[ -z "${PHPCS_REPORT_ALL_LINES:-}" ]]; then
    echo "Scanning full codebase: ${BRANCH} (new violations vs ${BASE_BRANCH})"

    BASE_REPORT="$(mktemp)"; TMPFILES+=("${BASE_REPORT}")
    echo "  phpcs on ${BASE_BRANCH}..."
    git -C "${GIT_ROOT}" checkout -q "${BASE_REF}"
    run_phpcs "${BASE_REPORT}"

    BASE_KEYS="$(mktemp)"; TMPFILES+=("${BASE_KEYS}")
    extract_violation_keys "${BASE_REPORT}" > "${BASE_KEYS}"
    BASE_VIOLATION_COUNT="$(wc -l < "${BASE_KEYS}" | tr -d ' ')"

    CURRENT_REPORT="$(mktemp)"; TMPFILES+=("${CURRENT_REPORT}")
    echo "  phpcs on ${BRANCH}..."
    git -C "${GIT_ROOT}" checkout -q "${BRANCH_REF}"
    run_phpcs "${CURRENT_REPORT}"

    CURRENT_VIOLATION_COUNT="$(extract_violation_keys "${CURRENT_REPORT}" | wc -l | tr -d ' ')"
    filter_new_violations "${CURRENT_REPORT}" "${BASE_KEYS}" > "${REPORT_FILE}"
    echo "  Base violations: ${BASE_VIOLATION_COUNT} | Current violations: ${CURRENT_VIOLATION_COUNT}"
else
    echo "Scanning full codebase: ${BRANCH} (all violations)"
    git -C "${GIT_ROOT}" checkout -q "${BRANCH_REF}"
    run_phpcs "${REPORT_FILE}"
fi

append_summary "${REPORT_FILE}"

if grep -qE '^[[:space:]]*[0-9]+[[:space:]]+\|[[:space:]]+(ERROR|WARNING)' "${REPORT_FILE}"; then
    echo "Report written to ${REPORT_FILE} (violations found)." >&2
    exit 1
else
    echo "Report written to ${REPORT_FILE} (no violations)."
    exit 0
fi
