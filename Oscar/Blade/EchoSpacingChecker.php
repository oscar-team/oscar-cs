<?php

declare(strict_types=1);

namespace Oscar\Blade;

/**
 * Finds Blade escaped / unescaped echo regions with incorrect spacing around delimiters.
 *
 * Requires exactly one ASCII space (0x20) after the opening delimiter and before the closing delimiter,
 * except for Blade comments and @verbatim regions (skipped).
 */
final class EchoSpacingChecker
{
    /**
     * @return list<array{line: int, column: int, code: string, message: string}>
     */
    public static function check(string $blade): array
    {
        $violations = [];
        $n          = strlen($blade);
        $i          = 0;

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

            if ($i + 3 <= $n && substr($blade, $i, 3) === '{!!') {
                [$line, $col] = self::lineColAt($blade, $i);
                if (self::hasExactlyOneSpaceAfterOpening($blade, $i, $n, 3) === false) {
                    $violations[] = [
                        'line' => $line,
                        'column' => $col,
                        'code' => 'SpaceAfterOpening',
                        'message' => 'Blade unescaped echo must have exactly one space after {!!',
                    ];
                }
                $innerStart = (($blade[$i + 3] ?? '') === ' ') ? ($i + 4) : ($i + 3);
                $close      = self::findClosingWithStringAware($blade, $innerStart, $n, '!!}');
                if ($close === null) {
                    break;
                }
                if (self::hasExactlyOneSpaceBeforeUnescapedClose($blade, $close) === false) {
                    [$line, $col] = self::lineColAt($blade, $close);
                    $violations[] = [
                        'line' => $line,
                        'column' => $col,
                        'code' => 'SpaceBeforeClosing',
                        'message' => 'Blade unescaped echo must have exactly one space before !!}',
                    ];
                }
                $i = $close + 3;
                continue;
            }

            if ($i + 2 <= $n && substr($blade, $i, 2) === '{{' && ($i + 3 >= $n || $blade[$i + 2] !== '-')) {
                [$line, $col] = self::lineColAt($blade, $i);
                if (self::hasExactlyOneSpaceAfterOpening($blade, $i, $n, 2) === false) {
                    $violations[] = [
                        'line' => $line,
                        'column' => $col,
                        'code' => 'SpaceAfterOpening',
                        'message' => 'Blade echo must have exactly one space after {{',
                    ];
                }
                $innerStart = (($blade[$i + 2] ?? '') === ' ') ? ($i + 3) : ($i + 2);
                $closeOffset = self::findClosingDoubleBrace($blade, $innerStart, $n);
                if ($closeOffset === null) {
                    break;
                }
                if (self::hasExactlyOneSpaceBeforeEscapedClose($blade, $closeOffset) === false) {
                    [$line, $col] = self::lineColAt($blade, $closeOffset);
                    $violations[] = [
                        'line' => $line,
                        'column' => $col,
                        'code' => 'SpaceBeforeClosing',
                        'message' => 'Blade echo must have exactly one space before }}',
                    ];
                }
                $i = $closeOffset + 2;
                continue;
            }

            if ($i + 3 <= $n && substr($blade, $i, 3) === '{{-') {
                [$line, $col] = self::lineColAt($blade, $i);
                if (self::hasExactlyOneSpaceAfterOpening($blade, $i, $n, 3) === false) {
                    $violations[] = [
                        'line' => $line,
                        'column' => $col,
                        'code' => 'SpaceAfterOpening',
                        'message' => 'Blade trimmed echo must have exactly one space after {{-',
                    ];
                }
                $innerStart = (($blade[$i + 3] ?? '') === ' ') ? ($i + 4) : ($i + 3);
                $closeOffset = self::findClosingDoubleBrace($blade, $innerStart, $n);
                if ($closeOffset === null) {
                    break;
                }
                if (self::hasExactlyOneSpaceBeforeEscapedClose($blade, $closeOffset) === false) {
                    [$line, $col] = self::lineColAt($blade, $closeOffset);
                    $violations[] = [
                        'line' => $line,
                        'column' => $col,
                        'code' => 'SpaceBeforeClosing',
                        'message' => 'Blade trimmed echo must have exactly one space before }} or -}}',
                    ];
                }
                $i = $closeOffset + 2;
                continue;
            }

            ++$i;
        }

        return $violations;
    }

    /**
     * After `{{` (length 2) or `{{-` / `{!!` (length 3): exactly one ASCII space, then non-space (or end).
     */
    private static function hasExactlyOneSpaceAfterOpening(string $blade, int $i, int $n, int $openLen): bool
    {
        $sp = $i + $openLen;
        if ($sp >= $n || $blade[$sp] !== ' ') {
            return false;
        }
        $after = $sp + 1;

        return $after >= $n || $blade[$after] !== ' ';
    }

    /**
     * Before `}}`: either ` }}` with exactly one space (not `  }}`), or trim ` -}}` with exactly one space before `-`.
     */
    private static function hasExactlyOneSpaceBeforeEscapedClose(string $blade, int $close): bool
    {
        if ($close < 2) {
            return false;
        }
        if ($blade[$close - 1] === '-') {
            if ($close < 3) {
                return false;
            }

            return $blade[$close - 2] === ' ' && $blade[$close - 3] !== ' ';
        }

        if ($blade[$close - 1] !== ' ') {
            return false;
        }

        return $blade[$close - 2] !== ' ';
    }

    private static function hasExactlyOneSpaceBeforeUnescapedClose(string $blade, int $close): bool
    {
        if ($close < 2) {
            return false;
        }
        if ($blade[$close - 1] !== ' ') {
            return false;
        }

        return $blade[$close - 2] !== ' ';
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

    private static function findClosingDoubleBrace(string $s, int $start, int $n): ?int
    {
        $i     = $start;
        $inStr = '';
        $esc   = false;
        while ($i + 1 < $n) {
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
                } elseif ($ch === '}' && $s[$i + 1] === '}') {
                    return $i;
                }
            }
            ++$i;
        }

        return null;
    }

    private static function findClosingWithStringAware(string $s, int $start, int $n, string $close): ?int
    {
        $cl = strlen($close);
        $i  = $start;
        $inStr = '';
        $esc   = false;
        while ($i < $n) {
            if ($i + $cl <= $n && substr($s, $i, $cl) === $close && $inStr === '') {
                return $i;
            }
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
                }
            }
            ++$i;
        }

        return null;
    }
}
