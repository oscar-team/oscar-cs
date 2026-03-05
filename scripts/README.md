# Scripts

Two validation scripts for the Oscar coding standard:

| Script | Purpose | Intended use |
|--------|---------|--------------|
| `validate-pr-oscar-cs.sh` | Validates PHP files **changed in a PR** | CI and local PR review |
| `validate-codebase-oscar-cs.sh` | Validates **all PHP files** in the codebase | Local audits only |

For a detailed explanation of the internal logic, see [VALIDATION-LOGIC.md](VALIDATION-LOGIC.md).

### Prerequisites

- PHP in `PATH`.
- Oscar standard and phpcs installed: run `composer install` once in `oscar-cs/`.
- Both scripts resolve `phpcs` and the Oscar ruleset from their own location (`oscar-cs/vendor/bin/phpcs`, `oscar-cs/Oscar/ruleset.xml`) — no need to reference `oscar-cs/` in your arguments.

### Common parameters

**`SITE_PATH`** — Path to the PHP project to scan (e.g. `../../infra/sites/hejoscar.dk` from the scripts folder, or an absolute path). The git repository is auto-detected from it (works for standalone repos and monorepos).

**Report output** — controlled by environment variables:

| Variable | Effect |
|----------|--------|
| `PHPCS_REPORT_PATH` | Directory where the report file is written (default: current working directory). The filename is always auto-generated (branches + timestamp). |
| `PHPCS_REPORT_FILE` | Full path override. Takes precedence over `PHPCS_REPORT_PATH`. |

---

### Running from oscar-cs/scripts/

All examples below assume you have changed into the `oscar-cs/scripts/` directory:

```bash
cd oscar-cs/scripts
```

Point reports to the repository root (two levels up from `oscar-cs/scripts/`):

```bash
export PHPCS_REPORT_PATH=../..
```

---

## validate-pr-oscar-cs.sh — PR validation (CI)

Scans only PHP files changed between two branches and reports violations on the lines you actually added.

**Git side effects:** one temporary checkout to `CURRENT` (restored on exit). No base-branch scan.

### Usage

```bash
validate-pr-oscar-cs.sh SITE_PATH [CURRENT [BASE [PHPCS_REPORT_ALL_LINES]]]
```

- **`CURRENT`** — New/PR branch to validate (default: current git HEAD).
- **`BASE`** — Merge-target branch (default: `master`).
- **4th arg** — Set to `1`, `all`, `yes`, or `true` to report all violations in changed files (not just on changed lines). Same effect as `PHPCS_REPORT_ALL_LINES=1`.

**Default behaviour:** Find PHP files changed in `BASE...CURRENT`, run phpcs on them (on `CURRENT`), and filter the report to violations on **lines you actually added** (`+` lines in the diff). Pre-existing violations on unchanged context lines are excluded.

### PR examples

```bash
export PHPCS_REPORT_PATH=../..   # set once; reports go to the repo root

# Validate Billie-new-flow against master (default BASE)
./validate-pr-oscar-cs.sh ../../infra/sites/hejoscar.dk Billie-new-flow

# Validate against a specific base branch
./validate-pr-oscar-cs.sh ../../infra/sites/hejoscar.dk Billie-new-flow COR-291-multiprocessor-support

# Report all violations in changed files (not just on changed lines)
./validate-pr-oscar-cs.sh ../../infra/sites/hejoscar.dk Billie-new-flow master 1
```

### PR report name

`phpcs-report-<CURRENT>-to-<BASE>-YYYYMMDD-HHMMSS.txt`

---

## validate-codebase-oscar-cs.sh — full codebase validation (local)

Scans all PHP files under the project. **Not recommended for CI** — requires two branch checkouts in default mode.

**Git side effects:** temporary checkout to `PHPCS_BASE_BRANCH` (for the base scan), then to `BRANCH` (restored on exit). Two checkouts in default mode; one in `PHPCS_REPORT_ALL_LINES` mode.

### Usage

```bash
validate-codebase-oscar-cs.sh SITE_PATH [BRANCH]
```

- **`BRANCH`** — Branch to scan (default: current git HEAD).
- **`PHPCS_BASE_BRANCH`** — Base branch for comparison (default: `master`).

**Default behaviour:** Run phpcs on `PHPCS_BASE_BRANCH` and on `BRANCH`, subtract violations already present in the base, and report only violations that are **new in `BRANCH`**. Pre-existing technical debt is not surfaced.

**`PHPCS_REPORT_ALL_LINES=1`** — Skip the base comparison and report every violation found in `BRANCH` (full audit mode).

### Codebase examples

```bash
export PHPCS_REPORT_PATH=../..   # set once; reports go to the repo root

# New violations on current branch vs master (default)
./validate-codebase-oscar-cs.sh ../../infra/sites/hejoscar.dk

# Scan a specific branch (new vs master)
./validate-codebase-oscar-cs.sh ../../infra/sites/hejoscar.dk Billie-new-flow

# Compare against a custom base
PHPCS_BASE_BRANCH=develop ./validate-codebase-oscar-cs.sh ../../infra/sites/hejoscar.dk Billie-new-flow

# Full audit — every violation, no base comparison
PHPCS_REPORT_ALL_LINES=1 ./validate-codebase-oscar-cs.sh ../../infra/sites/hejoscar.dk
```

### Codebase report name

- New violations only: `phpcs-report-codebase-<BRANCH>-vs-<BASE>-YYYYMMDD-HHMMSS.txt`
- All violations: `phpcs-report-codebase-<BRANCH>-YYYYMMDD-HHMMSS.txt`

---

## Uncommitted changes

Both scripts need to switch branches temporarily. If you have **uncommitted or staged changes** when either script runs, it will ask before touching anything:

```
You have uncommitted changes. The script needs to temporarily switch branches.
Your changes will be stashed now and restored automatically when the script finishes.
Continue? [y/N]
```

- **`y`** — changes are stashed, validation runs, stash is popped automatically when done.
- Anything else — script aborts, nothing is touched.

Untracked files are never stashed or affected.

In CI the working tree is always clean, so this prompt never appears.

## Report

- Written to **`PHPCS_REPORT_PATH`** (directory) with an auto-generated timestamped filename, or to the **current working directory** if `PHPCS_REPORT_PATH` is not set.
- Set `PHPCS_REPORT_FILE` (full path) to override the filename entirely.
- A **summary** is appended at the end: total errors/warnings, then a breakdown by type sorted by count (most frequent first).
- **Exit code**: `0` if no violations; `1` if violations were found (suitable for CI).

## Interrupting the scripts

| How | What happens |
|-----|-------------|
| **Ctrl+C** | Script stops immediately. Branch is restored, stash is popped. Safe. |
| **`kill PID`** (SIGTERM) | Same as Ctrl+C. Safe. |
| **`kill -9 PID`** (SIGKILL) | Cannot be trapped. Cleanup does **not** run. |

If killed with `kill -9`, recover manually:

```bash
git checkout <your-branch>   # restore your branch
git stash pop                 # if a stash was created
```

To check whether a stash was left behind: `git stash list` — look for an entry labelled `validate-pr-oscar-cs temporary stash` or `validate-codebase-oscar-cs temporary stash`.

---

## CI integration

`validate-pr-oscar-cs.sh` is designed to run in CI with no interactive prompts (the stash prompt only fires when uncommitted changes are present, which never happens in CI).

The script needs to be accessible in the CI environment. Two supported approaches:

### Option A — HTTPS clone with a GitHub PAT

Clone `oscar-cs` on the fly using a fine-grained GitHub Personal Access Token stored as a masked CI variable (`OSCAR_CS_GITHUB_TOKEN`, read-only access to this repo is enough). No SSH key or agent setup required.

```yaml
# GitLab CI example
script:
  - |
    git fetch origin "$CI_MERGE_REQUEST_TARGET_BRANCH_NAME" \
    && git clone --depth 1 https://oauth2:$OSCAR_CS_GITHUB_TOKEN@github.com/oscar-team/oscar-cs.git /tmp/oscar-cs \
    && composer install --no-dev --no-interaction --prefer-dist --working-dir=/tmp/oscar-cs \
    || exit 2
  - bash /tmp/oscar-cs/scripts/validate-pr-oscar-cs.sh "$CI_PROJECT_DIR" "$CI_MERGE_REQUEST_SOURCE_BRANCH_NAME" "$CI_MERGE_REQUEST_TARGET_BRANCH_NAME"
```

If the clone or install fails the job exits non-zero, which is advisory in the default setup (`allow_failure: true`). In enforcing mode, consider whether a failed clone should block — if not, add `|| { echo "Warning: setup failed, skipping."; exit 0; }` to the setup chain.

### Option B — Composer dev dependency (recommended)

Add `oscar-team/per-coding-standard` and `squizlabs/php_codesniffer` to the project's `composer.json` as dev dependencies. After `composer install`, the script is available at `vendor/oscar-team/per-coding-standard/scripts/` and phpcs at `vendor/bin/phpcs`. No clone step needed in CI.

**`composer.json`:**

```json
"require-dev": {
    "oscar-team/per-coding-standard": "dev-master",
    "squizlabs/php_codesniffer": "^3.10"
},
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/oscar-team/oscar-cs.git"
    }
]
```

**GitLab CI job** (reuses the `vendor/` artifact from the build job):

```yaml
phpcs-pr:
  stage: lint
  rules:
    - if: '$CI_PIPELINE_SOURCE == "merge_request_event" && $PHPCS_ENFORCE'
      allow_failure:
        exit_codes: [2]
    - if: '$CI_PIPELINE_SOURCE == "merge_request_event"'
      allow_failure: true
  variables:
    GIT_DEPTH: 0
    PHPCS_REPORT_FILE: "$CI_PROJECT_DIR/phpcs-report.txt"
  dependencies:
    - build-php   # provides vendor/ artifact
  script:
    - git fetch origin "$CI_MERGE_REQUEST_TARGET_BRANCH_NAME"
    - bash vendor/oscar-team/per-coding-standard/scripts/validate-pr-oscar-cs.sh "$CI_PROJECT_DIR" "$CI_MERGE_REQUEST_SOURCE_BRANCH_NAME" "$CI_MERGE_REQUEST_TARGET_BRANCH_NAME"
  artifacts:
    when: always
    paths:
      - phpcs-report.txt
    expire_in: 30 days
```

Set `PHPCS_ENFORCE=1` in GitLab → Settings → CI/CD → Variables to switch from advisory (warning) to blocking mode. No YAML change required.

---

## Known acceptable violations

For known acceptable violations (e.g. translation files, long config string values), see the main [Oscar README](../README.md#line-length-120-chars--known-acceptable-violations). Note: comment-only lines (docblocks, block comments, inline `//`) are already skipped by the `ignoreComments` setting and are never reported.
