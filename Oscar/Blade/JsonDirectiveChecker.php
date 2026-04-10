<?php

declare(strict_types=1);

namespace Oscar\Blade;

/**
 * Detects discouraged @json(...) argument patterns (array literal, multi-arg calls, multiple top-level args).
 */
final class JsonDirectiveChecker
{
    private const MESSAGE = '@json should only be used with a variable or function or method calls with '
        . 'maximum 1 argument. See https://github.com/laravel/framework/pull/23655 for more details.';

    /**
     * @return list<array{line: int, column: int, message: string}>
     */
    public static function check(string $blade): array
    {
        $violations = [];
        foreach (self::findJsonDirectiveInners($blade) as $hit) {
            $inner = trim($hit['inner']);
            if ($inner === '') {
                continue;
            }

            if (self::isArrayLiteralExpression($inner) === true) {
                $violations[] = [
                    'line' => $hit['line'],
                    'column' => $hit['column'],
                    'message' => self::MESSAGE,
                ];
                continue;
            }

            if (self::hasTopLevelComma($inner) === true) {
                $violations[] = [
                    'line' => $hit['line'],
                    'column' => $hit['column'],
                    'message' => self::MESSAGE,
                ];
                continue;
            }

            if (self::hasInnermostParenGroupWithCommaSeparatedArgs($inner) === true) {
                $violations[] = [
                    'line' => $hit['line'],
                    'column' => $hit['column'],
                    'message' => self::MESSAGE,
                ];
            }
        }

        return $violations;
    }

    /**
     * @return list<array{inner: string, line: int, column: int}>
     */
    private static function findJsonDirectiveInners(string $blade): array
    {
        $hits  = [];
        $n     = strlen($blade);
        $i     = 0;

        while ($i < $n) {
            if ($i + 4 <= $n && substr($blade, $i, 4) === '{{--') {
                $end = strpos($blade, '--}}', $i + 4);
                if ($end === false) {
                    break;
                }
                $i = $end + 4;
                continue;
            }

            if (self::isDirective($blade, $i, $n, 'verbatim')) {
                $endStart = self::findClosingDirectiveStart($blade, $n, $i, 'endverbatim');
                if ($endStart === null) {
                    break;
                }
                $i = $endStart + strlen('@endverbatim');
                continue;
            }

            if ($i + 5 <= $n && strcasecmp(substr($blade, $i, 5), '@json') === 0) {
                $after = $i + 5;
                if ($after < $n && ctype_alpha($blade[$after])) {
                    ++$i;
                    continue;
                }

                $j = $after;
                while (
                    $j < $n
                    && ($blade[$j] === ' ' || $blade[$j] === "\t" || $blade[$j] === "\n" || $blade[$j] === "\r")
                ) {
                    ++$j;
                }
                if ($j < $n && $blade[$j] === '(') {
                    $close = self::scanBalancedParens($blade, $j, $n);
                    if ($close === null) {
                        break;
                    }
                    $inner = substr($blade, $j + 1, $close - $j - 1);
                    [$line, $col] = self::lineColAt($blade, $i);
                    $hits[] = ['inner' => $inner, 'line' => $line, 'column' => $col];
                    $i = $close + 1;
                    continue;
                }
            }

            ++$i;
        }

        return $hits;
    }

    private static function isArrayLiteralExpression(string $trimmedInner): bool
    {
        return (bool) preg_match('/^\[/', $trimmedInner);
    }

    /**
     * Comma at depth 0 — e.g. two arguments to @json(...) itself.
     */
    private static function hasTopLevelComma(string $inner): bool
    {
        return self::commaAtDepth($inner, 0);
    }

    private static function commaAtDepth(string $s, int $targetDepth): bool
    {
        $depth  = 0;
        $len    = strlen($s);
        $inStr  = '';
        $esc    = false;

        for ($i = 0; $i < $len; ++$i) {
            $ch = $s[$i];

            if ($inStr !== '') {
                if ($esc) {
                    $esc = false;
                } elseif ($ch === '\\' && ($inStr === '"' || $inStr === "'")) {
                    $esc = true;
                } elseif ($ch === $inStr) {
                    $inStr = '';
                }

                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inStr = $ch;
                continue;
            }

            if ($ch === '(' || $ch === '[' || $ch === '{') {
                ++$depth;
                continue;
            }
            if ($ch === ')' || $ch === ']' || $ch === '}') {
                --$depth;
                continue;
            }
            if ($ch === ',' && $depth === $targetDepth) {
                return true;
            }
        }

        return false;
    }

    /**
     * Innermost (...), no nested parens in mask, with a comma — and not a sole [...] literal inside.
     */
    private static function hasInnermostParenGroupWithCommaSeparatedArgs(string $inner): bool
    {
        $work = $inner;
        while (preg_match('/\(([^()]*)\)/', $work, $m)) {
            $content = trim($m[1]);
            if ($content === '') {
                $work = self::replaceFirstOccurrence($work, $m[0], '0');
                continue;
            }
            if (preg_match('/^\[/', $content)) {
                $work = self::replaceFirstOccurrence($work, $m[0], '0');
                continue;
            }
            if (strpos($content, ',') !== false) {
                return true;
            }
            $work = self::replaceFirstOccurrence($work, $m[0], '0');
        }

        return false;
    }

    private static function replaceFirstOccurrence(string $haystack, string $needle, string $replace): string
    {
        $pos = strpos($haystack, $needle);
        if ($pos === false) {
            return $haystack;
        }

        return substr($haystack, 0, $pos) . $replace . substr($haystack, $pos + strlen($needle));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function lineColAt(string $s, int $offset): array
    {
        $line = 1;
        $col  = 1;
        $len  = min($offset, strlen($s));
        for ($p = 0; $p < $len; ++$p) {
            if ($s[$p] === "\n") {
                ++$line;
                $col = 1;
            } else {
                ++$col;
            }
        }

        return [$line, $col];
    }

    private static function isDirective(string $s, int $i, int $n, string $name): bool
    {
        $len = strlen($name);
        if ($i + 1 + $len > $n) {
            return false;
        }
        if ($s[$i] !== '@') {
            return false;
        }
        if (strcasecmp(substr($s, $i + 1, $len), $name) !== 0) {
            return false;
        }
        $after = $i + 1 + $len;

        return $after >= $n || !ctype_alnum($s[$after]);
    }

    private static function findClosingDirectiveStart(string $s, int $n, int $from, string $endName): ?int
    {
        $needle = '@' . $endName;
        $pos    = $from;
        while ($pos < $n) {
            $p = strpos($s, $needle, $pos);
            if ($p === false) {
                return null;
            }
            $after = $p + strlen($needle);
            if ($after >= $n || !ctype_alnum($s[$after])) {
                return $p;
            }
            $pos = $p + 1;
        }

        return null;
    }

    /**
     * @return int|null offset of closing )
     */
    private static function scanBalancedParens(string $s, int $openPos, int $n): ?int
    {
        if ($openPos >= $n || $s[$openPos] !== '(') {
            return null;
        }
        $depth = 0;
        $i     = $openPos;
        $inStr = '';
        $esc   = false;
        while ($i < $n) {
            $ch = $s[$i];
            if ($inStr !== '') {
                if ($esc) {
                    $esc = false;
                } elseif ($ch === '\\' && ($inStr === '"' || $inStr === "'")) {
                    $esc = true;
                } elseif ($ch === $inStr) {
                    $inStr = '';
                }
            } else {
                if ($ch === '"' || $ch === "'") {
                    $inStr = $ch;
                } elseif ($ch === '(') {
                    ++$depth;
                } elseif ($ch === ')') {
                    --$depth;
                    if ($depth === 0) {
                        return $i;
                    }
                }
            }
            ++$i;
        }

        return null;
    }
}
