#!/usr/bin/env bash
# Shared helpers for validate-pr-oscar-cs.sh and validate-codebase-oscar-cs.sh.
# Source this file; do not execute directly.
# Compatible with bash 4+ and zsh 5+. Invoke main scripts as:
#   bash validate-*.sh ...   (or ./validate-*.sh on systems with bash 4+)
#   zsh  validate-*.sh ...   (macOS default shell; no extra installs needed)

# zsh: enable bash-compatible BASH_REMATCH captures and 0-based array indexing.
# In bash, ZSH_VERSION is unset so this no-ops safely (even on bash 3).
[[ -n "${ZSH_VERSION:-}" ]] && setopt BASH_REMATCH KSH_ARRAYS 2>/dev/null || true

# check_bash_version
# Exits with a clear message if the shell is unsupported (bash < 4 or zsh < 5).
# Call this immediately after sourcing the lib.
check_bash_version() {
    if [[ -n "${ZSH_VERSION:-}" ]]; then
        local major="${ZSH_VERSION%%.*}"
        if [[ "$major" -lt 5 ]]; then
            echo "Error: zsh 5 or later is required (found zsh ${ZSH_VERSION})." >&2
            exit 1
        fi
    elif [[ "${BASH_VERSINFO[0]}" -lt 4 ]]; then
        echo "Error: bash 4 or later is required (found bash ${BASH_VERSION})." >&2
        echo "On macOS, run with zsh (built-in) or install bash 4+ via Homebrew: brew install bash" >&2
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

# check_untracked_conflicts <ref...>
# Exits with a clear error if any untracked working-tree files would be
# overwritten by a checkout to any of the given refs.
# Only files Added in <ref> relative to HEAD are checked: files already
# tracked in HEAD cannot be untracked locally, so they cannot conflict.
# Requires GIT_ROOT to be set.
check_untracked_conflicts() {
    local -a untracked=()
    while IFS= read -r _f; do untracked+=("$_f"); done \
        < <(git -C "${GIT_ROOT}" ls-files --others --exclude-standard 2>/dev/null)
    [[ ${#untracked[@]} -eq 0 ]] && return 0

    local -A untracked_set=()
    local f
    for f in "${untracked[@]}"; do untracked_set["$f"]=1; done

    local ref
    local -a conflicts=()
    for ref in "$@"; do
        while IFS= read -r f; do
            [[ -n "${untracked_set["$f"]+_}" ]] && conflicts+=("$f")
        done < <(git -C "${GIT_ROOT}" diff --name-only --diff-filter=A HEAD "${ref}" 2>/dev/null || true)
    done

    if [[ ${#conflicts[@]} -gt 0 ]]; then
        echo "Error: untracked files would be overwritten by checkout:" >&2
        printf '  %s\n' "${conflicts[@]}" >&2
        echo "Move or remove them before running this script." >&2
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
        local _bline
        local -a _out=()
        for _bline in "${buffer[@]}"; do
            if [[ "$_bline" =~ ^FOUND ]]; then
                _out+=("$found_line")
            elif [[ "$_bline" =~ ^PHPCBF ]]; then
                [[ $kept_fixable -gt 0 ]] && _out+=("PHPCBF CAN FIX THE ${kept_fixable} MARKED SNIFF VIOLATIONS AUTOMATICALLY")
            else
                _out+=("$_bline")
            fi
        done
        printf '%s\n' "${_out[@]}"
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
    msg="${msg%% \(PER *}"
    echo "${msg%%[[:space:]]}"
}

# append_summary <report_file>
# Appends a SUMMARY block to the report file with totals and per-type breakdown,
# sorted by count descending. Streams normalized keys to temp files (one per
# severity) so memory stays O(unique message types) rather than O(violations).
append_summary() {
    local report="$1"
    local total_errors=0 total_warnings=0 key line _cnt _msg
    local _re_err='^[[:space:]]*[0-9]+[[:space:]]+[|][[:space:]]+ERROR[[:space:]]+[|][[:space:]]*(.+)'
    local _re_warn='^[[:space:]]*[0-9]+[[:space:]]+[|][[:space:]]+WARNING[[:space:]]+[|][[:space:]]*(.+)'
    local _err_tmp _warn_tmp
    _err_tmp="$(mktemp)"; _warn_tmp="$(mktemp)"
    TMPFILES+=("$_err_tmp" "$_warn_tmp")

    while IFS= read -r line; do
        if [[ "$line" =~ $_re_err ]]; then
            ((total_errors++)) || true
            key="$(normalize_summary_msg "${BASH_REMATCH[1]}")"
            [[ -n "$key" ]] && printf '%s\n' "$key" >> "$_err_tmp"
        elif [[ "$line" =~ $_re_warn ]]; then
            ((total_warnings++)) || true
            key="$(normalize_summary_msg "${BASH_REMATCH[1]}")"
            [[ -n "$key" ]] && printf '%s\n' "$key" >> "$_warn_tmp"
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
        if [[ -s "$_err_tmp" ]]; then
            echo "By error type:"
            sort "$_err_tmp" | uniq -c | sort -rn | \
            while read -r _cnt _msg; do printf "  %3d  %s\n" "$_cnt" "$_msg"; done
            echo ""
        fi
        if [[ -s "$_warn_tmp" ]]; then
            echo "By warning type:"
            sort "$_warn_tmp" | uniq -c | sort -rn | \
            while read -r _cnt _msg; do printf "  %3d  %s\n" "$_cnt" "$_msg"; done
        fi
    } >> "$report"
    rm -f "$_err_tmp" "$_warn_tmp"
}
