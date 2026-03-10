# Oscar Coding Standard

Coding standard for the Oscar technical team that layers project-specific sniffs on top of the PER Coding Style 3.0 specification. The standard is distributed as a PHP_CodeSniffer custom standard located at `Oscar/ruleset.xml`.

## Custom PER Sniffs
- **Namespaces.UseGrouping** – rejects grouped `use` statements that append more than one namespace separator inside the group. (PER §3)
- **Attributes.AttributePlacement** – validates attribute spacing, line placement, and parameter formatting, including adjacency to docblocks. (PER §12)
- **Closures.ShortClosure** – enforces arrow function spacing, indentation and semicolon placement rules. (PER §7.1)
- **Functions.EmptyBody** – requires empty methods/functions to collapse to `{} ` inline bodies with a preceding space. (PER §4.4)
- **Formatting.TrailingComma** – ensures multi-line lists end with a comma and single-line lists do not. (PER §2.6)

All other PER/PSR-12 expectations are inherited by referencing the upstream `PSR12` standard.

## Installation
1. Install PHP_CodeSniffer (globally or per-project), for example:
   ```bash
   composer global require squizlabs/php_codesniffer
   ```
2. Install Oscar standard (globally or per-project), for example:
   ```bash
   composer global require oscar-team/per-coding-standard
   ```
3. Register the Oscar standard so PHPCS can discover it:
   ```bash
   phpcs --config-set installed_paths path/to/oscar-team/per-coding-standard
   ```
   Example for global:
   ```bash
   phpcs --config-set installed_paths ~/.composer/vendor/oscar-team/per-coding-standard
   ```
   Example for project:
   ```bash
   phpcs --config-set installed_paths ~/Projects/my-project/vendor/oscar-team/per-coding-standard
   ```
   Alternatively, provide the ruleset path directly when running `phpcs`.
4. Run `phpcs -i` to confirm that `Oscar` appears in the installed standards list.

## Usage
- Analyse code:
  ```bash
  phpcs --standard=Oscar path/to/your/php/files
  ```
- Auto-fix fixable violations (where safe):
  ```bash
  phpcbf --standard=Oscar path/to/your/php/files
  ```

## Testing
- Install dependencies with `composer install`.
- Run the automated fixtures with `./tests/run-phpcs.sh`.

## Development Notes
- Baseline PSR-12 is referenced, with `Squiz.WhiteSpace.ScopeClosingBrace.ContentBefore` excluded to avoid conflicts with inline empty bodies.
- Sniffs live under `Oscar/Sniffs`.
- Error codes on each sniff reference the matching PER section to aid suppression and maintenance.
- Extend the ruleset by adding new `<rule>` entries under `Oscar/ruleset.xml`; PHPCS will autoload them via the configured installed path.

### Line length (120 chars) — known acceptable violations
The rule `Generic.Files.LineLength` (with `ignoreComments` enabled) skips any line whose only non-whitespace content is a comment token (docblocks `/** */`, block comments `/* */`, and inline `//` comments — including indented ones). Only actual code lines are checked.

Some code lines are legitimately long and acceptable to leave as-is:

- **Language/translation files** — Long lines in `resources/lang/*` (translation strings) are common; splitting can hurt readability or i18n tooling. Treat as acceptable when appropriate.
- **Config files with long string values** — Array values with long env()-calls, URLs, or human-readable strings (e.g. `'description' => 'Comprehensive API...'`) may be awkward to split. Break across lines or use `// phpcs:ignore Generic.Files.LineLength` for that specific line if splitting hurts readability.




----------

# Oscar CS Pre-commit Hook — Setup Guide

This guide explains how to set up the **Oscar CS pre-commit hook** so your PHP changes are checked against our coding standard before each commit.


## Purpose

The hook:

- **Enforces the Oscar coding standard** on PHP code so we keep a consistent style and avoid common issues.
- **Runs only on lines you changed** — it does not fail the commit because of existing violations elsewhere in the file.
- **Skips non-PHP and excluded paths** — Blade templates (`.blade.php`) and language files (`resources/lang/`) are not checked, since they often contain non-standard formatting (e.g. translation arrays).

If the hook finds violations on your changed lines, the commit is blocked until you fix them (or explicitly bypass with `--no-verify` when appropriate).


## What you need

- **Composer** (global commands will be used).
- **PHP** (the same one you use for the project).
- **jq** (for parsing PHPCS output). Install with: `brew install jq` (macOS).



## Step-by-step setup

For the installation please refer to above guide.

**Once installation is done then check that Oscar(Coding Standard) is available:**

```bash
phpcs -i
```

You should see `Oscar` in the list of installed coding standards.

### 4. Add Composer’s global bin to your PATH (optional but recommended)

So you can run `phpcs` from any terminal:

**On macOS / Linux with Zsh**, add this to your `~/.zshrc`:

```bash
export PATH="$HOME/.composer/vendor/bin:$PATH"
```

Then reload your shell or run:

```bash
source ~/.zshrc
```

Confirm:

```bash
which phpcs
# Should print something like: /Users/yourname/.composer/vendor/bin/phpcs
```

### 5. Install the pre-commit hook in this repo

Copy the script from the **Pre-commit script** section at the bottom of this page and paste it into `.git/hooks/pre-commit` (create the file if it doesn’t exist). Then make it executable:

```bash
chmod +x .git/hooks/pre-commit
```

The hook is now active: it will run automatically on every `git commit`.



## What the hook checks

| Checked | Not checked |
|--------|-------------|
| Staged `.php` files | `.blade.php` files |
| Only **lines you added or modified** in the commit | Files under `resources/lang/` |
| Oscar coding standard | Unstaged files, other file types |



## Bypassing the hook

To commit without running the hook (use sparingly):

```bash
git commit --no-verify -m "Your message"
```



## Troubleshooting

- **“phpcs not found”**  
  Install PHPCS and the Oscar standard (steps 1–2), then add Composer’s global bin to your PATH (step 4). If you skip step 4, the hook still uses `~/.composer/vendor/bin/phpcs` and will work as long as that path exists.

- **“Oscar” standard not found**  
  Run step 3 again with the correct path. Then run `phpcs -i` and confirm `Oscar` is listed.

- **Hook doesn’t run**  
  Ensure the file is executable: `chmod +x .git/hooks/pre-commit`. If needed, copy the script again from the bottom of this page into `.git/hooks/pre-commit`.

- **jq: command not found**  
  Install jq (e.g. `brew install jq` on macOS). The hook needs it to filter violations to only your changed lines.



## Re-installing the hook (e.g. after clone)

New clones don’t get `.git/hooks` from the repo. After cloning, follow **step 5** again: copy the script from the section below into `.git/hooks/pre-commit`, then run `chmod +x .git/hooks/pre-commit`.



## Pre-commit script

Copy everything below into `.git/hooks/pre-commit`:

```bash
#!/usr/bin/env bash
# Oscar CS pre-commit hook
# Blocks commits that introduce phpcs violations in staged PHP files.
# Only reports violations on lines you actually changed (added/modified in the staged diff).
# Uses global PHPCS and Oscar standard. Excludes .blade.php and resources/lang/ files.

REPO_ROOT="$(git rev-parse --show-toplevel | tr -d '\n')"
cd "$REPO_ROOT" || exit 1

# Use global phpcs (Oscar standard is registered via: phpcs --config-set installed_paths ~/.composer/vendor/oscar-team/per-coding-standard)
PHPCS_BIN="${HOME}/.composer/vendor/bin/phpcs"
STANDARD="Oscar"

if [ ! -x "$PHPCS_BIN" ]; then
    echo "Pre-commit: phpcs not found. Install: composer global require squizlabs/php_codesniffer oscar-team/per-coding-standard"
    echo "Then: phpcs --config-set installed_paths ~/.composer/vendor/oscar-team/per-coding-standard"
    echo "Skipping Oscar CS check."
    exit 0
fi

# Only staged .php files; exclude .blade.php and all files under resources/lang/
FILES=$(git diff --cached --name-only --diff-filter=ACM | grep '\.php$' | grep -v '\.blade\.php$' | grep -v '^resources/lang/')

if [ -z "$FILES" ]; then
    echo "Pre-commit: No staged PHP files — skipping Oscar CS."
    exit 0
fi

echo "Pre-commit: Checking Oscar CS on staged PHP files (only your changed lines)..."

# Collect line numbers that were changed (added/modified) in the staged diff for each file.
# Git uses "+start,count" in hunk headers; when count is 1 it omits it: "+46 @@". We must handle both.
# Format: one line per "absolute_path|lineno" so we can filter phpcs output to those lines only.
CHANGED_TMP=$(mktemp)
trap 'rm -f "$CHANGED_TMP"' EXIT

for file in $FILES; do
    abspath="${REPO_ROOT}/${file}"
    git diff --cached -U0 -- "$file" | grep '^@@' | while read -r line; do
        # Match +N or +N,M (N = start line, M = line count; omit M when 1)
        if [[ "$line" =~ \+([0-9]+)(,([0-9]+))? ]]; then
            start="${BASH_REMATCH[1]}"
            count="${BASH_REMATCH[3]:-1}"
            if [ -n "$start" ] && [ "$count" -gt 0 ] 2>/dev/null; then
                end=$((start + count - 1))
                for ln in $(seq "$start" "$end" 2>/dev/null); do
                    echo "${abspath}|${ln}"
                done
            fi
        fi
    done
done >> "$CHANGED_TMP"

# If no changed lines (e.g. only deletions), skip check
if [ ! -s "$CHANGED_TMP" ]; then
    echo "Pre-commit: No added/modified lines in staged PHP files — skipping Oscar CS."
    exit 0
fi

# Run phpcs with global Oscar standard (extensions=php; .blade and lang already excluded from FILES)
PHPCS_JSON=$(mktemp)
trap 'rm -f "$CHANGED_TMP" "$PHPCS_JSON"' EXIT
# shellcheck disable=SC2086
"${PHPCS_BIN}" --standard="${STANDARD}" --extensions=php --report=json -q $FILES > "$PHPCS_JSON" 2>/dev/null

# Build JSON array of "path|line" for changed lines (for jq)
CHANGED_JSON=$(jq -R -s 'split("\n") | map(select(length > 0))' < "$CHANGED_TMP")

# Filter to violations only on changed lines; output as a single JSON array for the report
VIOLATIONS=$(jq --argjson changed "$CHANGED_JSON" '
  [
    .files | to_entries[] | .key as $path |
    .value.messages[] | select(($path + "|" + (.line | tostring)) as $k | $changed | index($k) != null) |
    { file: $path, line: .line, column: .column, type: .type, message: .message, source: .source }
  ]
' "$PHPCS_JSON" 2>/dev/null)

if [ -z "$VIOLATIONS" ] || ! echo "$VIOLATIONS" | jq -e 'length > 0' >/dev/null 2>&1; then
    echo "Pre-commit: No Oscar CS violations on your changed lines."
    exit 0
fi

# Print report: only violations on your changed lines
echo "Oscar CS violations on your changed lines:"
echo ""
echo "$VIOLATIONS" | jq -r '
  group_by(.file) | .[] |
  "FILE: " + (.[0].file) + "\n----------------------------------------------------------------------\n" +
  ([.[] | "  " + (.line | tostring) + " | " + .type + " | " + .message + " | " + .source] | join("\n")) + "\n"
'
echo "Fix the above (only on lines you changed) before committing."
echo "To bypass: git commit --no-verify"
exit 1
```

