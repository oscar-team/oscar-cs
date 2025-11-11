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
