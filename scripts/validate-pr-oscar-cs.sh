#!/usr/bin/env bash
# Validate PHP files changed in a PR against the Oscar coding standard.
#
# Finds PHP files changed between BASE and CURRENT, temporarily checks out CURRENT,
# runs phpcs on those files, and by default filters the report to violations on
# lines that were actually added in CURRENT (not pre-existing context lines).
#
# Side effects:
#   - git checkout to CURRENT (restored on exit).
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
Usage: validate-pr-oscar-cs.sh SITE_PATH [CURRENT [BASE [PHPCS_REPORT_ALL_LINES]]]

  SITE_PATH    Path to the PHP project to scan (e.g. infra/sites/hejoscar.dk, or '.' when inside it).
               phpcs and the Oscar ruleset are resolved from the script's own location (oscar-cs/).
  CURRENT      New/PR branch to validate (default: current git HEAD).
  BASE         Merge-target branch to compare against (default: master).
  4th arg      Set to 1/all/yes/true to report all violations in changed files (not just changed lines).
               Same effect as PHPCS_REPORT_ALL_LINES=1 env var.

  Default behaviour: report only violations on lines that were added ('+' in the diff) in CURRENT.
  Pre-existing violations on unchanged context lines are excluded.

  Report directory: PHPCS_REPORT_PATH (default: current working directory).
  Report filename:  phpcs-report-<CURRENT>-to-<BASE>-YYYYMMDD-HHMMSS.txt
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
CURRENT_BRANCH="${2:-}"
BASE_BRANCH="${3:-master}"

if [[ -n "${4:-}" ]]; then
    case "$(printf '%s' "${4}" | tr '[:lower:]' '[:upper:]')" in
        1|ALL|YES|TRUE) export PHPCS_REPORT_ALL_LINES=1 ;;
    esac
fi

# ── setup ─────────────────────────────────────────────────────────────────────
setup_site "${SITE_ARG}"
setup_tools
check_prerequisites

[[ -z "${CURRENT_BRANCH}" ]] && CURRENT_BRANCH="$(git -C "${GIT_ROOT}" rev-parse --abbrev-ref HEAD)"
CURRENT_REF="$(resolve_ref "${CURRENT_BRANCH}")"
BASE_REF="$(resolve_ref "${BASE_BRANCH}")"

CURRENT_SAFE="$(sanitize_branch "${CURRENT_BRANCH}")"
BASE_SAFE="$(sanitize_branch "${BASE_BRANCH}")"
[[ -z "${CURRENT_SAFE}" ]] && CURRENT_SAFE="current"
[[ -z "${BASE_SAFE}" ]] && BASE_SAFE="base"

REPORT_DIR="${PHPCS_REPORT_PATH:-${PWD}}"
REPORT_DIR="${REPORT_DIR%/}"
REPORT_FILE="${PHPCS_REPORT_FILE:-${REPORT_DIR}/phpcs-report-${CURRENT_SAFE}-to-${BASE_SAFE}-$(date +%Y%m%d-%H%M%S).txt}"
check_report_dir "${REPORT_FILE}"

# ── ref validation ─────────────────────────────────────────────────────────────
if ! git -C "${GIT_ROOT}" rev-parse "${CURRENT_REF}" >/dev/null 2>&1; then
    echo "Error: ref '${CURRENT_BRANCH}' (resolved: ${CURRENT_REF}) not found." >&2; exit 1
fi
if ! git -C "${GIT_ROOT}" rev-parse "${BASE_REF}" >/dev/null 2>&1; then
    echo "Error: base ref '${BASE_BRANCH}' (resolved: ${BASE_REF}) not found." >&2; exit 1
fi

# ── untracked conflict preflight ──────────────────────────────────────────────
check_untracked_conflicts "${CURRENT_REF}"

# ── git safety (stash, cleanup, traps) ────────────────────────────────────────
setup_git_safety "validate-pr-oscar-cs temporary stash"

# ── changed PHP files ─────────────────────────────────────────────────────────
echo "Validating: ${CURRENT_BRANCH} (PR branch) → ${BASE_BRANCH} (merge target)"

if [[ "${SITE_FULL}" == "${GIT_ROOT}" ]]; then
    SITE_REL=""
else
    SITE_REL="${SITE_FULL#${GIT_ROOT}/}"
fi

if [[ -z "${SITE_REL}" ]]; then
    mapfile -t CHANGED_FILES < <(git -C "${GIT_ROOT}" diff --name-only "${BASE_REF}"..."${CURRENT_REF}" 2>/dev/null | grep '\.php$' || true)
else
    mapfile -t CHANGED_FILES < <(git -C "${GIT_ROOT}" diff --name-only "${BASE_REF}"..."${CURRENT_REF}" -- "${SITE_REL}" 2>/dev/null | grep '\.php$' || true)
fi

if [[ ${#CHANGED_FILES[@]} -eq 0 ]]; then
    echo "No PHP files changed between ${BASE_BRANCH} and ${CURRENT_BRANCH}." | tee "${REPORT_FILE}"
    echo "Report written to ${REPORT_FILE}"
    exit 0
fi

if [[ -n "${SITE_REL}" ]]; then
    PHPCS_FILES=("${CHANGED_FILES[@]#${SITE_REL}/}")
else
    PHPCS_FILES=("${CHANGED_FILES[@]}")
fi

# ── checkout and filter to existing files ─────────────────────────────────────
git -C "${GIT_ROOT}" checkout -q "${CURRENT_REF}"

EXISTING_FILES=()
for f in "${PHPCS_FILES[@]}"; do
    [[ -f "${SITE_FULL}/${f}" ]] && EXISTING_FILES+=("$f")
done

if [[ ${#EXISTING_FILES[@]} -eq 0 ]]; then
    echo "No changed PHP files to check (all may be deleted in ${CURRENT_BRANCH})." | tee "${REPORT_FILE}"
    echo "Report written to ${REPORT_FILE}"
    exit 0
fi

# ── changed-line set (+ lines only, not context lines) ────────────────────────
declare -A CHANGED_LINE_SET
if [[ -z "${PHPCS_REPORT_ALL_LINES:-}" ]]; then
    echo "Restricting to violations on changed lines only (default). Set PHPCS_REPORT_ALL_LINES=1 for full-file report."
    for f in "${EXISTING_FILES[@]}"; do
        while IFS= read -r lineno; do
            CHANGED_LINE_SET["${f}:${lineno}"]=1
        done < <(
            git -C "${GIT_ROOT}" diff "${BASE_REF}"..."${CURRENT_REF}" -- "$f" 2>/dev/null | awk '
                /^\+\+\+ / { next }
                /^--- /    { next }
                /^@@ / {
                    split($0, a, /[+,]/)
                    cur = a[3] + 0
                    next
                }
                /^\+/ { print cur; cur++; next }
                /^-/  { next }
                      { cur++ }
            '
        )
    done
fi

# ── run phpcs ─────────────────────────────────────────────────────────────────
PHPCS_EXIT=0
if ! (cd "${SITE_FULL}" && "${PHPCS_BIN}" --standard="${RULESET}" --extensions=php --report-file="${REPORT_FILE}" "${EXISTING_FILES[@]}"); then
    PHPCS_EXIT=1
fi

# ── validate report only contains diff files ──────────────────────────────────
report_path_to_relative() {
    local path="$1"
    path="${path#FILE: }"
    while [[ "$path" == [" "$'\t']* ]]; do path="${path#?}"; done
    path="${path%%[[:space:]]*}"
    path="${path%/}"
    if [[ "$path" == *"${SITE_NAME}/"* ]]; then
        path="${path#*${SITE_NAME}/}"
    fi
    path="${path#./}"
    path="${path#/}"
    echo "$path"
}

is_in_diff() {
    local r="$1" a r_suffix
    for a in "${EXISTING_FILES[@]}"; do
        if [[ "$r" == "$a" ]]; then return 0; fi
        # phpcs truncates long paths mid-word with "..." (e.g. "...ev/path/to/file.php").
        # Strip the unrecoverable leading fragment up to the first "/" to get a suffix that
        # starts at a genuine directory boundary, then require a "/" boundary in the match.
        if [[ "$r" == ...* ]]; then
            r_suffix="${r#*/}"
            if [[ -n "$r_suffix" && ( "$a" == "$r_suffix" || "$a" == */"$r_suffix" ) ]]; then return 0; fi
        fi
    done
    return 1
}

BAD_FILES=()
while IFS= read -r line; do
    [[ "$line" != FILE:* ]] && continue
    rel="$(report_path_to_relative "$line")"
    [[ -z "$rel" || "$rel" != *.php ]] && continue
    is_in_diff "$rel" || BAD_FILES+=("$rel")
done < "${REPORT_FILE}"
if [[ ${#BAD_FILES[@]} -gt 0 ]]; then
    printf 'Error: Report contains files not in the diff: %s\n' "${BAD_FILES[*]}" >&2
    exit 1
fi

# ── filter to changed lines (default) ─────────────────────────────────────────
# flush_block is provided by lib-oscar-cs.sh (dynamic scoping: accesses buffer, kept_* locals).
filter_report_to_changed_lines() {
    local report_file="$1"
    local current_file="" in_block=0 keep_this_violation=0 kept_any=0
    local kept_errors=0 kept_warnings=0 kept_fixable=0
    local -A kept_line_nums=()
    local -a buffer=()

    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ "$line" == FILE:* ]]; then
            flush_block
            in_block=1
            buffer+=("$line")
            current_file="$(report_path_to_relative "$line")"
            if [[ "$current_file" == ...* ]]; then
                current_file="${current_file#*/}"
            fi
            for a in "${EXISTING_FILES[@]}"; do
                if [[ "$a" == "$current_file" || "$a" == */"$current_file" || "${a#*/}" == "$current_file" ]]; then
                    current_file="$a"
                    break
                fi
            done
            keep_this_violation=0
        elif [[ $in_block -eq 1 ]]; then
            if [[ "$line" =~ ^[[:space:]]*([0-9]+)[[:space:]]+\|[[:space:]]+(ERROR|WARNING)[[:space:]]+\|[[:space:]]*(.+) ]]; then
                local line_num="${BASH_REMATCH[1]}" sev="${BASH_REMATCH[2]}" msg="${BASH_REMATCH[3]}"
                if [[ -n "${CHANGED_LINE_SET["${current_file}:${line_num}"]:-}" ]]; then
                    keep_this_violation=1
                    kept_any=1
                    if [[ "$sev" == "ERROR" ]]; then ((kept_errors++)) || true; else ((kept_warnings++)) || true; fi
                    [[ "$msg" == \[x\]* ]] && ((kept_fixable++)) || true
                    kept_line_nums["$line_num"]=1
                    buffer+=("$line")
                else
                    keep_this_violation=0
                fi
            elif [[ "$line" =~ ^[[:space:]]*\| ]]; then
                [[ $keep_this_violation -eq 1 ]] && buffer+=("$line")
            elif [[ "$line" =~ ^-+$ ]] || [[ "$line" =~ ^FOUND ]] || [[ "$line" =~ ^PHPCBF ]] || [[ "$line" =~ ^[[:space:]]*$ ]]; then
                keep_this_violation=0
                buffer+=("$line")
            else
                flush_block
                echo "$line"
                in_block=0
            fi
        else
            flush_block
            echo "$line"
        fi
    done < "$report_file"
    flush_block
}

if [[ -z "${PHPCS_REPORT_ALL_LINES:-}" && ${#CHANGED_LINE_SET[@]} -gt 0 ]]; then
    FILTER_TMP="$(mktemp)"; TMPFILES+=("${FILTER_TMP}")
    filter_report_to_changed_lines "${REPORT_FILE}" > "${FILTER_TMP}"
    mv "${FILTER_TMP}" "${REPORT_FILE}"
    if ! grep -qE '^[[:space:]]*[0-9]+[[:space:]]+\|[[:space:]]+(ERROR|WARNING)' "${REPORT_FILE}"; then
        PHPCS_EXIT=0
    fi
fi

append_summary "${REPORT_FILE}"

if [[ $PHPCS_EXIT -eq 0 ]]; then
    echo "Report written to ${REPORT_FILE} (no violations)."
    exit 0
else
    echo "Report written to ${REPORT_FILE} (violations found)." >&2
    exit 1
fi
