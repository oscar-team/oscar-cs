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

    private static function wrap(PhpFragment $f): string
    {
        return match ($f->kind) {
            'echo', 'unescaped' => 'echo (' . trim($f->code) . ');',
            'php_block' => trim($f->code),
            'php_inline' => self::wrapPhpInline($f->code),
            'directive' => self::wrapDirective($f),
            default => '',
        };
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
            'json' => "echo json_encode($inner);",
            'if', 'elseif', 'unless', 'isset', 'empty', 'auth', 'guest' => "if ($inner) {}",
            'switch' => "switch ($inner) {}",
            'case' => "case ($inner): break;",
            'for' => "for ($inner) {}",
            'foreach', 'forelse' => "foreach ($inner) {}",
            'while' => "while ($inner) {}",
            'can', 'elsecan' => "can($inner);",
            'cannot', 'elsecannot' => "cannot($inner);",
            default => "if ($inner) {}",
        };
    }
}
