<?php

declare(strict_types=1);

namespace Oscar\Blade;

/**
 * Builds a PHP-only buffer from Blade fragments with line alignment for PHPCS delegation.
 *
 * Synthetic file structure:
 * - Line 1: {@code <?php}
 * - Line 1+N: content for Blade line N (may be empty)
 *
 * Child PHPCS line C maps to Blade line C - 1 for C >= 2.
 */
final class SyntheticPhpBuilder
{
    /**
     * @param list<PhpFragment> $fragments
     */
    public static function build(string $bladeContent, array $fragments): string
    {
        $bladeLines = preg_split("/\r\n|\n|\r/", $bladeContent) ?: [];
        $count      = max(1, count($bladeLines));

        $byLine = [];
        foreach ($fragments as $f) {
            $line = $f->startLine;
            if ($line < 1) {
                $line = 1;
            }
            if (!isset($byLine[$line])) {
                $byLine[$line] = [];
            }
            $byLine[$line][] = $f;
        }
        ksort($byLine, SORT_NUMERIC);

        $lines = array_fill(0, $count, '');
        foreach ($byLine as $lineNum => $frags) {
            $idx = $lineNum - 1;
            if ($idx < 0 || $idx >= $count) {
                continue;
            }
            $parts = [];
            foreach ($frags as $f) {
                $parts[] = self::wrap($f);
            }
            $lines[$idx] = implode(' ', array_filter($parts, static fn(string $p): bool => $p !== ''));
        }

        $buf = "<?php\n";
        foreach ($lines as $line) {
            $buf .= $line . "\n";
        }

        return $buf;
    }

    /**
     * Map a line number from delegated PHPCS output to the Blade source line (1-based).
     */
    public static function childLineToBladeLine(int $childLine): ?int
    {
        if ($childLine < 2) {
            return null;
        }

        return $childLine - 1;
    }

    /**
     * Synthetic PHP line PHPCS sees for one Blade line (no leading {@code <?php} wrapper line).
     * Used to map child token columns back onto Blade source columns for IDE underlines.
     */
    public static function syntheticLineForBladeLine(string $bladeContent, int $bladeLine): string
    {
        if ($bladeLine < 1) {
            return '';
        }

        $parts = [];
        foreach (FragmentExtractor::extract($bladeContent) as $f) {
            if ($f->startLine === $bladeLine) {
                $w = self::wrap($f);
                if ($w !== '') {
                    $parts[] = $w;
                }
            }
        }

        return implode(' ', $parts);
    }

    private static function wrap(PhpFragment $f): string
    {
        return match ($f->kind) {
            'echo', 'unescaped' => 'echo (' . trim($f->code) . ');',
            'php_block' => self::wrapPhpBlockLine($f->code),
            'php_inline' => self::wrapPhpInline($f->code),
            'directive' => self::wrapDirective($f),
            default => '',
        };
    }

    /**
     * Single line from a @php … @endphp body (see {@see FragmentExtractor} split). Trims Blade
     * indentation; completes simple statements with a semicolon when needed for tokenization.
     */
    private static function wrapPhpBlockLine(string $code): string
    {
        $c = trim($code);
        if ($c === '') {
            return '';
        }
        if (str_ends_with($c, '}')) {
            return $c;
        }
        if (str_ends_with($c, '{')) {
            return $c;
        }
        if (str_ends_with($c, ':')) {
            return $c;
        }
        // Declaration / keyword lines that continue on the next row (brace on its own line).
        if (preg_match('/^function\s+\w+\s*\([^)]*\)\s*$/', $c) === 1) {
            return $c;
        }
        if (preg_match('/^(if|elseif|while|for|foreach)\s*\(/', $c) === 1 && str_ends_with($c, ')')) {
            return $c;
        }
        if (!str_ends_with($c, ';')) {
            $c .= ';';
        }

        return $c;
    }

    private static function wrapPhpInline(string $code): string
    {
        $c = trim($code);
        if ($c === '') {
            return '';
        }
        if (str_ends_with($c, '}')) {
            return $c;
        }
        if (!str_ends_with($c, ';')) {
            $c .= ';';
        }

        return $c;
    }

    private static function wrapDirective(PhpFragment $f): string
    {
        $name = strtolower((string) $f->directive);
        $inner = trim($f->code);

        return match ($name) {
            'json' => self::wrapJsonDirective($inner),
            'if', 'elseif', 'unless', 'isset', 'empty', 'auth', 'guest' => self::wrapConditionalDirective($inner),
            'switch' => self::wrapAnonymousClassFromArgs($inner),
            'case' => "case ($inner): break;",
            'for' => self::wrapForDirective($inner),
            'foreach', 'forelse' => self::wrapForeachDirective($inner),
            'while' => self::wrapWhileDirective($inner),
            'can', 'elsecan' => "can($inner);",
            'cannot', 'elsecannot' => "cannot($inner);",
            default => self::wrapAnonymousClassFromArgs($inner),
        };
    }

    /**
     * Blade @json — keep as echo; no braced control structure (avoids Squiz ControlSignature on synthetic one-liners).
     */
    private static function wrapJsonDirective(string $inner): string
    {
        if ($inner === '') {
            return 'echo json_encode(null);';
        }

        // Multi-line @json(...) must not embed raw newlines: one synthetic row per Blade line, or
        // child PHPCS line numbers no longer map with childLineToBladeLine().
        $normalized = trim(preg_replace('/\s+/', ' ', $inner) ?? '');

        return "echo json_encode($normalized);";
    }

    /**
     * Blade @if, @unless, etc. — ternary assignment avoids empty if-braces that violate Squiz ControlSignature on a single synthetic line.
     */
    private static function wrapConditionalDirective(string $inner): string
    {
        $condition = $inner === '' ? 'false' : $inner;

        return '$_ = (' . $condition . ') ? true : false;';
    }

    /**
     * Blade @extends, @section, @switch, and unknown directives — anonymous class constructor args match comma-separated call/list syntax.
     */
    private static function wrapAnonymousClassFromArgs(string $inner): string
    {
        if ($inner === '') {
            return 'new class () {};';
        }

        return "new class ($inner) {};";
    }

    private static function wrapForDirective(string $inner): string
    {
        if ($inner === '') {
            return 'for (;;);';
        }

        return "for ($inner);";
    }

    private static function wrapForeachDirective(string $inner): string
    {
        if ($inner === '') {
            return 'foreach ([] as $_);';
        }

        return "foreach ($inner);";
    }

    private static function wrapWhileDirective(string $inner): string
    {
        if ($inner === '') {
            return 'while (false);';
        }

        return "while ($inner);";
    }
}
