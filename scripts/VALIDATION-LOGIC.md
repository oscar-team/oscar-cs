# Validation script logic

This document explains how the two validation scripts work internally.

## Scripts at a glance

| Script | Git checkouts | When to use |
|--------|--------------|-------------|
| `validate-pr-oscar-cs.sh` | 1 — to `CURRENT` | CI and local PR review |
| `validate-codebase-oscar-cs.sh` | 2 — to `BASE`, then to `BRANCH` | Local audits only |

Both scripts share the same bash version check, path/tool setup, branch resolution, git safety (stash, cleanup, traps), report block flushing (`flush_block`), and summary logic — all via `lib-oscar-cs.sh`.
No source files are modified (no phpcbf).

---

## validate-pr-oscar-cs.sh

### Arguments

```
validate-pr-oscar-cs.sh SITE_PATH [CURRENT [BASE [PHPCS_REPORT_ALL_LINES]]]
```

- **`SITE_PATH`** — Path to the PHP project to scan. Resolved to an absolute path; the git repository is auto-detected via `git rev-parse --show-toplevel`.
- **`CURRENT`** — New/PR branch (default: current HEAD).
- **`BASE`** — Merge-target branch (default: `master`).
- **4th arg or `PHPCS_REPORT_ALL_LINES=1`** — Disable line filtering; report all violations in changed files.
- **`PHPCS_REPORT_PATH`** — Output directory (default: current working directory). Filename is auto-generated.
- **`PHPCS_REPORT_FILE`** — Full path override; takes precedence over `PHPCS_REPORT_PATH`.

### Steps

#### 1. Shared setup

See [Shared setup](#shared-setup) below.

#### 2. List of changed PHP files

Runs `git diff --name-only BASE...CURRENT` (three-dot merge-base diff). If `GIT_ROOT` differs from `SITE_FULL` (monorepo case), the diff is restricted to the `SITE_REL` subdirectory and the prefix is stripped so all paths are relative to `SITE_FULL`. Deleted files are removed from the list after the checkout step.

If no PHP files changed, the script writes a short message to the report file and exits successfully.

#### 3. Temporary checkout

Checks out `CURRENT_REF` (quiet). Then builds `EXISTING_FILES`: only changed files that still exist on disk on the PR branch (deleted files are skipped).

#### 4. Changed-line set

If `PHPCS_REPORT_ALL_LINES` is not set, the script builds an associative array `CHANGED_LINE_SET` with keys `"path:lineno"` for every line **actually added** in the PR (lines with a `+` in the diff — not context lines).

For each file, `git diff BASE...CURRENT -- <file>` is parsed with `awk`:

- When a hunk header `@@ -old,oc +new,nc @@` is encountered, set `cur = new`.
- When a line starts with `+` (but not `+++`), mark `cur` as changed and increment.
- When a line starts with `-`, do nothing (removed; does not exist in new file).
- Otherwise (context line), increment `cur` **without** marking it.

**Why `+` lines only, not the full hunk range:** A unified diff hunk always includes unchanged context lines around the actual change. Those lines are pre-existing code. If a violation exists on a context line, it predates the PR. An earlier implementation used the full hunk range and incorrectly surfaced pre-existing violations.

#### 5. Running phpcs

`cd` to `SITE_FULL` and run phpcs with the Oscar ruleset, `--extensions=php`, `--report-file=<REPORT_FILE>`, and the list of `EXISTING_FILES`.

#### 6. Report validation

The script checks that every `FILE: ...` path in the report corresponds to a file in the diff. phpcs paths may be absolute or truncated (`...pp/Models/Foo.php`); matching is by suffix. If any reported file is not in the diff, the script exits with an error.

#### 7. Filtering to changed lines (default)

When `PHPCS_REPORT_ALL_LINES` is not set and `CHANGED_LINE_SET` is non-empty, `filter_report_to_changed_lines` rewrites the report:

- For each violation line, keeps it only if `current_file:lineno` is in `CHANGED_LINE_SET`.
- Continuation lines (lines starting with `|`) are kept only if the preceding violation was kept.
- **FILE blocks with no kept violations are dropped entirely** — never produces a "FOUND X ERROR" block with no error lines.
- The `FOUND X ERRORS AND Y WARNINGS AFFECTING Z LINES` and `PHPCBF CAN FIX...` header lines are rewritten with accurate counts for the kept violations.

The filtered report overwrites the original. If no violation lines remain, the exit code is set to 0.

---

## validate-codebase-oscar-cs.sh

### Arguments

```
validate-codebase-oscar-cs.sh SITE_PATH [BRANCH]
```

- **`SITE_PATH`** — Path to the PHP project to scan.
- **`BRANCH`** — Branch to scan (default: current HEAD).
- **`PHPCS_BASE_BRANCH`** — Base branch for comparison (default: `master`).
- **`PHPCS_REPORT_ALL_LINES=1`** — Skip base comparison; report every violation in `BRANCH`.
- **`PHPCS_REPORT_PATH`** / **`PHPCS_REPORT_FILE`** — same as PR script.

### Steps

#### 1. Shared setup

See [Shared setup](#shared-setup) below.

#### 2. Default: new violations only

1. **Checkout base branch** (`PHPCS_BASE_BRANCH`, default `master`) and run phpcs on all PHP files in the site (with `--ignore=*/vendor/*,*/node_modules/*,*/storage/*`). Capture the report in a temp file. The temp file is `touch`ed after phpcs exits — phpcs deletes its `--report-file` when killed mid-run, so the `touch` ensures the file always exists for the next step even after an interrupt.
2. **Extract violation keys** from the base report: one `file:line:severity:message` string per violation, with file paths normalised relative to the site. Load into an associative array.
3. **Checkout the scan branch** and run phpcs again. Capture the report.
4. **Filter new violations**: read the current report line by line; for each FILE block, emit only violations whose key is absent from the base set. FILE blocks with no remaining violations are suppressed. The `FOUND X ERRORS` and `PHPCBF CAN FIX...` lines are rewritten with correct counts.
5. Write the filtered report to the output file.

**Conservative by design:** matching is exact (same file path, line number, severity, and message). If surrounding code shifts line numbers in the scan branch, a violation may appear "new" because its line number changed. This means the comparison can over-report, but it will never miss a genuinely introduced violation.

#### 3. PHPCS_REPORT_ALL_LINES=1

Skip steps 1–4; just checkout the scan branch, run phpcs once, and write the full unfiltered report.

---

## Shared setup

Both scripts go through the same setup before any phpcs or git operations.

### Path resolution

`SITE_FULL` is the absolute path of `SITE_PATH`. `SITE_NAME` is its basename (e.g. `hejoscar.dk`), used to normalize absolute paths in phpcs output to paths relative to the site.

`GIT_ROOT` is found with `git -C "$SITE_FULL" rev-parse --show-toplevel`. It equals `SITE_FULL` when the site has its own `.git`; otherwise it is the enclosing monorepo root.

`PHPCS_BIN` and `RULESET` are derived from `SCRIPT_DIR` (the directory containing the script): `SCRIPT_DIR/../vendor/bin/phpcs` and `SCRIPT_DIR/../Oscar/ruleset.xml`.

### Branch resolution

Branches are resolved so remote-only refs work:

- If `git rev-parse <ref>` succeeds → use `<ref>`.
- Else if the ref is not already `origin/*` and `origin/<ref>` exists → use `origin/<ref>`.
- Otherwise the ref is used as-is (may fail later with a clear error).

So you can pass `Billie-new-flow` even when only `origin/Billie-new-flow` exists.

### Uncommitted changes and stash

Before any checkout, the script checks for uncommitted or staged changes (`git diff` and `git diff --cached`). If any are found, it asks for confirmation:

```
You have uncommitted changes. The script needs to temporarily switch branches.
Your changes will be stashed now and restored automatically when the script finishes.
Continue? [y/N]
```

If the user confirms, changes are stashed with `git stash push -m "<script-name> temporary stash"` and `STASH_DONE=1` is set. Untracked files are not stashed.

In CI the working tree is always clean, so this prompt never appears.

### Git cleanup and signal handling

The script saves the current HEAD (`symbolic-ref` if on a branch, `rev-parse` if detached) and sets up two traps:

- **`trap cleanup EXIT`** — runs when the shell exits for any reason (normal completion, `set -e` error, or after a signal-triggered `exit`).
- **`trap 'exit 130' INT TERM`** — on Ctrl+C (SIGINT) or `kill` (SIGTERM), immediately calls `exit 130`, which then fires the EXIT trap.

Using `exit` in the INT/TERM handler (rather than calling `cleanup` directly) is important: without it, bash runs the trap handler and then *continues execution* after the interrupted command, which can cause follow-on errors (e.g. trying to read a report file that phpcs deleted when it was killed). With `exit`, bash terminates before reaching any further statements.

The `cleanup` function:
1. Checks a `CLEANUP_DONE` flag to prevent running twice.
2. Restores the original branch: `git checkout <PREV_REF>`.
3. Pops the stash if one was created (`STASH_DONE=1`).
4. Removes any temporary files (`mktemp` outputs tracked in `TMPFILES`).

`kill -9` (SIGKILL) cannot be trapped; if the process is killed this way, cleanup does not run. Recovery: `git checkout <your-branch>` then `git stash pop` (check `git stash list` for a `validate-pr-oscar-cs temporary stash` or `validate-codebase-oscar-cs temporary stash` entry).

---

## Summary appendix

`append_summary` (in `lib-oscar-cs.sh`) scans the final report for `  <n> | ERROR | ...` and `  <n> | WARNING | ...` lines:

- Counts total errors and warnings.
- Normalizes messages (strips variable parts like "; contains 121 characters") and groups identical messages.
- Appends a **SUMMARY (generated)** section with totals and "By error type" / "By warning type" lists, **sorted by count descending** (most frequent first).

---

## Report line numbers: use the scan branch

PHPCS runs after the script checks out the scan branch. So the report's file paths and **line numbers refer to the files as they are on the scan branch**, not on the base branch. Always look at the file **on the scan branch** (e.g. `git show <branch>:path/to/file` or after checking out that branch).

Verified on source branch `open-api-doc-generation` (after the awk fix):

| File | Reported line | What that line is in the diff | Included in report? |
|------|--------------|-------------------------------|---------------------|
| InsurancePolicy.php | 33 | `[CONTEXT]` — trait use line, unchanged; PR only added a docblock above it | **No — correctly excluded** |
| GlobalInsuranceBenefit.php | 23 | `[CONTEXT]` — trait use line, unchanged; PR only added a docblock above it | **No — correctly excluded** |
| AuthController.php | 80 | `[CONTEXT]` — closing brace, unchanged; PR only added OA annotations in the same hunk | **No — correctly excluded** |
| BookingController.php | 1132, 1269 | `[CONTEXT]` — `]]);` lines, unchanged; PR only added a docblock in the same hunk | **No — correctly excluded** |

All four were pre-existing violations that an earlier implementation incorrectly surfaced. After the awk fix they are absent from the report.

---

## Design choices

| Choice | Reason |
|--------|--------|
| Two separate scripts (PR vs codebase) | Isolates CI-safe concerns: the PR script does one checkout and never scans the base branch. The codebase script does two checkouts and is intentionally local-only. |
| Shared `lib-oscar-cs.sh` | Keeps bash version check, path/tool setup, branch resolution, git safety (stash + cleanup + traps), `flush_block`, and summary logic in one place without coupling the two scripts. |
| `SITE_PATH` points directly to the project | Simpler interface: pass `.` when inside the project; no need to know the parent monorepo path. |
| phpcs and ruleset resolved from script location | Callers never reference `oscar-cs/` in arguments; the script always uses the version co-located with its own code. |
| Auto-detect git root from `SITE_PATH` | Works for standalone repos and nested repos (GIT_ROOT is the enclosing monorepo root). |
| New-violations-only as default in both scripts | Focuses the report on code that was actually changed; avoids surfacing pre-existing technical debt. |
| Stash uncommitted changes with confirmation | Prevents git from refusing to switch branches; user is informed and must confirm before anything is touched. |
| `trap 'exit 130' INT TERM` (not `trap cleanup INT TERM`) | Without `exit`, bash runs the trap handler but continues to the next statement after the interrupted command. Calling `exit` stops bash immediately, then EXIT fires cleanup. Prevents follow-on errors. |
| `touch` report file after `run_phpcs` (codebase) | phpcs deletes its `--report-file` when killed by SIGINT. Without the `touch`, the next step that reads the file would fail with "No such file or directory". An empty file is harmless. |
| Checkout scan branch for phpcs | PHPCS must analyse the correct version of the files. |
| Three-dot diff `BASE...CURRENT` | Uses merge base so the "changed" set reflects what the PR actually introduces vs the base. |
| Parse `+` lines only (not full hunk range) | Hunk ranges include context lines (unchanged code). Only lines with a leading `+` are genuinely new. |
| Drop FILE blocks with no kept violations | Avoids misleading "FOUND 1 ERROR" blocks with no error line after filtering. |
| Rewrite FOUND/PHPCBF headers after filtering | Per-file counts must match the actual violations shown, not the unfiltered phpcs output. |
| Match truncated report paths by suffix | PHPCS can shorten paths; suffix matching ensures they are not treated as "not in diff". |
| Summary sorted by count descending | Most frequent violation types appear first, making the summary easier to act on. |
| `PHPCS_REPORT_PATH` sets the output directory | Decouples the directory choice from the filename; the filename is always auto-generated with branch names and a timestamp. |
