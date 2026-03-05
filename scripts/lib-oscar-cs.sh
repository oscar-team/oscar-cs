#!/usr/bin/env bash
# Shared helpers for validate-pr-oscar-cs.sh and validate-codebase-oscar-cs.sh.
# Source this file; do not execute directly.

# check_bash_version
# Exits with a clear message if bash < 4 is detected.
# Call this immediately after sourcing the lib (the lib itself is safe to source on bash 3
# because it contains only function definitions with no top-level bash-4+ syntax).
check_bash_version() {
    if [[ "${BASH_VERSINFO[0]}" -lt 4 ]]; then
        echo "Error: bash 4 or later is required (found bash ${BASH_VERSION})." >&2
        echo "On macOS, install a newer bash via Homebrew: brew install bash" >&2
        exit 1
    fi
}

# setup_site <site_path>
# Resolves SITE_FULL to an absolute path and sets SITE_NAME (basename) and GIT_ROOT.
# GIT_ROOT is the git repository that contains SITE_FULL (may equal SITE_FULL for nested repos).
# Sets globals: SITE_FULL, SITE_NAME, GIT_ROOT
setup_site() {
    local site_arg="$1"
    if [[ -z "$site_arg" ]]; then
        echo "Error: SITE_PATH is required." >&2
        return 1
    fi
    if [[ ! -d "$site_arg" ]]; then
        echo "Error: SITE_PATH '$site_arg' is not a directory." >&2
        return 1
    fi
    SITE_FULL="$(cd "$site_arg" && pwd)"
    SITE_NAME="$(basename "${SITE_FULL}")"
    if ! GIT_ROOT="$(git -C "${SITE_FULL}" rev-parse --show-toplevel 2>/dev/null)"; then
        echo "Error: no git repository found at or above ${SITE_FULL}." >&2
        return 1
    fi
}

# setup_tools
# Sets PHPCS_BIN and RULESET from SCRIPT_DIR (the directory containing the calling script).
# SCRIPT_DIR must be set by the caller before sourcing this lib.
# When oscar-cs is installed as a Composer dependency of another project, phpcs lives in the
# host project's vendor/bin/ (three levels up) rather than oscar-cs's own vendor/. The fallback
# handles that case so the scripts work both standalone and as a Composer dep.
# Sets globals: PHPCS_BIN, RULESET
setup_tools() {
    PHPCS_BIN="${SCRIPT_DIR}/../vendor/bin/phpcs"
    if [[ ! -f "${PHPCS_BIN}" ]]; then
        PHPCS_BIN="${SCRIPT_DIR}/../../../bin/phpcs"
    fi
    RULESET="${SCRIPT_DIR}/../Oscar/ruleset.xml"
}

# resolve_ref <ref>
# Prints the resolved git ref: uses the ref as-is if it resolves, otherwise tries origin/<ref>.
# Requires GIT_ROOT to be set.
resolve_ref() {
    local ref="$1"
    [[ -z "$ref" ]] && return
    if git -C "${GIT_ROOT}" rev-parse "$ref" >/dev/null 2>&1; then
        echo "$ref"; return
    fi
    if [[ "$ref" != origin/* ]] && git -C "${GIT_ROOT}" rev-parse "origin/${ref}" >/dev/null 2>&1; then
        echo "origin/${ref}"; return
    fi
    echo "$ref"
}

# sanitize_branch <name>
# Prints a filename-safe version of a branch name.
sanitize_branch() {
    echo "$1" | sed 's/[^a-zA-Z0-9_.-]/_/g' | sed 's/__*/_/g; s/^_\|_$//g'
}

# check_prerequisites
# Verifies that PHPCS_BIN is executable, RULESET exists, and SITE_FULL is a directory.
# Requires PHPCS_BIN, RULESET, and SITE_FULL to be set.
check_prerequisites() {
    if [[ ! -x "${PHPCS_BIN}" ]]; then
        echo "Error: phpcs not found at ${PHPCS_BIN}. Run 'composer install' in oscar-cs/." >&2
        return 1
    fi
    if [[ ! -f "${RULESET}" ]]; then
        echo "Error: Oscar ruleset not found at ${RULESET}." >&2
        return 1
    fi
    if [[ ! -d "${SITE_FULL}" ]]; then
        echo "Error: site directory not found: ${SITE_FULL}." >&2
        return 1
    fi
}

# check_report_dir <report_file>
# Exits with a clear error if the parent directory of the report file does not exist.
check_report_dir() {
    local dir
    dir="$(dirname "$1")"
    if [[ ! -d "${dir}" ]]; then
        echo "Error: report directory '${dir}' does not exist." >&2
        return 1
    fi
}

# setup_git_safety <stash_label>
# Saves the current HEAD, optionally stashes uncommitted changes (with user confirmation),
# defines the cleanup() function, and installs EXIT/INT/TERM traps.
# Sets globals: PREV_REF, STASH_DONE, TMPFILES, CLEANUP_DONE
# Requires GIT_ROOT to be set.
setup_git_safety() {
    local stash_label="${1:-validate-oscar-cs temporary stash}"

    PREV_REF="$(git -C "${GIT_ROOT}" symbolic-ref -q HEAD 2>/dev/null)" \
        || PREV_REF="$(git -C "${GIT_ROOT}" rev-parse HEAD)"

    STASH_DONE=0
    if ! git -C "${GIT_ROOT}" diff --quiet 2>/dev/null \
        || ! git -C "${GIT_ROOT}" diff --cached --quiet 2>/dev/null; then
        echo ""
        echo "You have uncommitted changes. The script needs to temporarily switch branches."
        echo "Your changes will be stashed now and restored automatically when the script finishes."
        printf "Continue? [y/N] "
        read -r REPLY
        if [[ ! "${REPLY}" =~ ^[Yy]$ ]]; then
            echo "Aborted." >&2
            exit 1
        fi
        git -C "${GIT_ROOT}" stash push -q -m "${stash_label}"
        STASH_DONE=1
        echo "Changes stashed. Starting validation..."
        echo ""
    fi

    TMPFILES=()
    CLEANUP_DONE=0
    cleanup() {
        [[ $CLEANUP_DONE -eq 1 ]] && return
        CLEANUP_DONE=1
        git -C "${GIT_ROOT}" checkout -q "${PREV_REF}" 2>/dev/null || true
        if [[ $STASH_DONE -eq 1 ]]; then
            echo "Restoring stashed changes..."
            git -C "${GIT_ROOT}" stash pop || \
                echo "Warning: could not automatically restore stash. Run 'git stash pop' manually." >&2
        fi
        [[ ${#TMPFILES[@]} -gt 0 ]] && rm -f "${TMPFILES[@]}" 2>/dev/null || true
    }
    # EXIT handles normal exit and set -e errors. INT/TERM call 'exit' so bash stops immediately
    # (without 'exit', bash runs the trap handler but may continue to the next statement).
    # 'exit' then triggers EXIT → cleanup. CLEANUP_DONE prevents double-run.
    # kill -9 (SIGKILL) cannot be trapped; see README for recovery steps.
    trap cleanup EXIT
    trap 'exit 130' INT TERM
}

# flush_block
# Flushes the current FILE block buffer to stdout, rewriting the FOUND/PHPCBF header lines
# with counts derived from the kept violations, then resets all block-level counters.
# Uses dynamic scoping: expects the caller to have locals named buffer, kept_any, kept_errors,
# kept_warnings, kept_fixable, and kept_line_nums.
flush_block() {
    if [[ $kept_any -gt 0 && ${#buffer[@]} -gt 0 ]]; then
        local total_lines=${#kept_line_nums[@]}
        local err_word="ERRORS"; [[ $kept_errors -eq 1 ]] && err_word="ERROR"
        local warn_word="WARNINGS"; [[ $kept_warnings -eq 1 ]] && warn_word="WARNING"
        local line_word="LINES"; [[ $total_lines -eq 1 ]] && line_word="LINE"
        local found_line="FOUND ${kept_errors} ${err_word} AND ${kept_warnings} ${warn_word} AFFECTING ${total_lines} ${line_word}"
        local i
        for i in "${!buffer[@]}"; do
            if [[ "${buffer[$i]}" =~ ^FOUND ]]; then
                buffer[$i]="$found_line"
            elif [[ "${buffer[$i]}" =~ ^PHPCBF ]]; then
                if [[ $kept_fixable -gt 0 ]]; then
                    buffer[$i]="PHPCBF CAN FIX THE ${kept_fixable} MARKED SNIFF VIOLATIONS AUTOMATICALLY"
                else
                    unset 'buffer[$i]'
                fi
            fi
        done
        printf '%s\n' "${buffer[@]}"
    fi
    buffer=()
    kept_any=0
    kept_errors=0; kept_warnings=0; kept_fixable=0; kept_line_nums=()
}

# normalize_summary_msg <msg>
# Strips variable parts from a violation message so similar messages group together.
normalize_summary_msg() {
    local msg="$1"
    msg="${msg:0:120}"
    msg="${msg%%; contains *}"
    msg="${msg%%; expected at least *}"
    msg="${msg%% (PER *}"
    echo "${msg%%[[:space:]]}"
}

# append_summary <report_file>
# Appends a SUMMARY block to the report file with totals and per-type breakdown,
# sorted by count descending.
append_summary() {
    local report="$1"
    local total_errors=0 total_warnings=0 has_errors=0 has_warnings=0 key
    declare -A type_errors=()
    declare -A type_warnings=()
    while IFS= read -r line; do
        if [[ "$line" =~ ^[[:space:]]*[0-9]+[[:space:]]+\|[[:space:]]+ERROR[[:space:]]+\|[[:space:]]*(.+) ]]; then
            ((total_errors++)) || true
            key="$(normalize_summary_msg "${BASH_REMATCH[1]}")"
            if [[ -n "$key" ]]; then
                has_errors=1
                type_errors["$key"]=$((${type_errors["$key"]:-0} + 1))
            fi
        elif [[ "$line" =~ ^[[:space:]]*[0-9]+[[:space:]]+\|[[:space:]]+WARNING[[:space:]]+\|[[:space:]]*(.+) ]]; then
            ((total_warnings++)) || true
            key="$(normalize_summary_msg "${BASH_REMATCH[1]}")"
            if [[ -n "$key" ]]; then
                has_warnings=1
                type_warnings["$key"]=$((${type_warnings["$key"]:-0} + 1))
            fi
        fi
    done < "$report"
    {
        echo ""
        echo "==============================================================================="
        echo "SUMMARY (generated)"
        echo "==============================================================================="
        echo "Total errors:   $total_errors"
        echo "Total warnings: $total_warnings"
        echo ""
        if [[ $has_errors -eq 1 ]]; then
            echo "By error type:"
            while IFS=$'\t' read -r cnt msg; do
                printf "  %3d  %s\n" "$cnt" "$msg"
            done < <(
                for k in "${!type_errors[@]}"; do
                    printf '%s\t%s\n' "${type_errors[$k]}" "$k"
                done | sort -t $'\t' -k1,1rn
            )
            echo ""
        fi
        if [[ $has_warnings -eq 1 ]]; then
            echo "By warning type:"
            while IFS=$'\t' read -r cnt msg; do
                printf "  %3d  %s\n" "$cnt" "$msg"
            done < <(
                for k in "${!type_warnings[@]}"; do
                    printf '%s\t%s\n' "${type_warnings[$k]}" "$k"
                done | sort -t $'\t' -k1,1rn
            )
        fi
    } >> "$report"
}
