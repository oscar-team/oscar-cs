<?php

declare(strict_types=1);

namespace Oscar\Blade;

/**
 * Finds Blade escaped / unescaped echo regions with incorrect spacing around delimiters.
 *
 * Requires exactly one ASCII space after `{{`, `{{-`, and `{!!`, and exactly one before `}}`, `-}}`, and
 * `!!}` (no compact style, no doubled spaces). Blade comments and @verbatim regions are skipped.
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
                if (self::hasInvalidSpaceAfterOpening($blade, $i, $n, 3) === true) {
                    $problem = self::problemOffsetForSpaceAfterOpening($blade, $i, $n, 3);
                    [$line, $col] = self::lineColAt($blade, $problem);
                    $violations[] = [
                        'line' => $line,
                        'column' => $col,
                        'code' => 'SpaceAfterOpening',
                        'message' => 'Blade unescaped echo must have exactly one ASCII space after {!!',
                    ];
                }
                $innerStart = (($blade[$i + 3] ?? '') === ' ') ? ($i + 4) : ($i + 3);
                $close      = self::findClosingWithStringAware($blade, $innerStart, $n, '!!}');
                if ($close === null) {
                    break;
                }
                if (self::hasInvalidSpaceBeforeUnescapedClose($blade, $close) === true) {
                    $problem = self::problemOffsetForSpaceBeforeUnescapedClose($blade, $close);
                    [$line, $col] = self::lineColAt($blade, $problem);
                    $violations[] = [
                        'line' => $line,
                        'column' => $col,
                        'code' => 'SpaceBeforeClosing',
                        'message' => 'Blade unescaped echo must have exactly one ASCII space before !!}',
                    ];
                }
                $i = $close + 3;
                continue;
            }

            if ($i + 2 <= $n && substr($blade, $i, 2) === '{{' && ($i + 3 >= $n || $blade[$i + 2] !== '-')) {
                if (self::hasInvalidSpaceAfterOpening($blade, $i, $n, 2) === true) {
                    $problem = self::problemOffsetForSpaceAfterOpening($blade, $i, $n, 2);
                    [$line, $col] = self::lineColAt($blade, $problem);
                    $violations[] = [
                        'line' => $line,
                        'column' => $col,
                        'code' => 'SpaceAfterOpening',
                        'message' => 'Blade echo must have exactly one ASCII space after {{',
                    ];
                }
                $innerStart = (($blade[$i + 2] ?? '') === ' ') ? ($i + 3) : ($i + 2);
                $closeOffset = self::findClosingDoubleBrace($blade, $innerStart, $n);
                if ($closeOffset === null) {
                    break;
                }
                if (self::hasInvalidSpaceBeforeEscapedClose($blade, $closeOffset) === true) {
                    $problem = self::problemOffsetForSpaceBeforeEscapedClose($blade, $closeOffset);
                    [$line, $col] = self::lineColAt($blade, $problem);
                    $violations[] = [
                        'line' => $line,
                        'column' => $col,
                        'code' => 'SpaceBeforeClosing',
                        'message' => 'Blade echo must have exactly one ASCII space before }}',
                    ];
                }
                $i = $closeOffset + 2;
                continue;
            }

            if ($i + 3 <= $n && substr($blade, $i, 3) === '{{-') {
                if (self::hasInvalidSpaceAfterOpening($blade, $i, $n, 3) === true) {
                    $problem = self::problemOffsetForSpaceAfterOpening($blade, $i, $n, 3);
                    [$line, $col] = self::lineColAt($blade, $problem);
                    $violations[] = [
                        'line' => $line,
                        'column' => $col,
                        'code' => 'SpaceAfterOpening',
                        'message' => 'Blade trimmed echo must have exactly one ASCII space after {{-',
                    ];
                }
                $innerStart = (($blade[$i + 3] ?? '') === ' ') ? ($i + 4) : ($i + 3);
                $closeOffset = self::findClosingDoubleBrace($blade, $innerStart, $n);
                if ($closeOffset === null) {
                    break;
                }
                if (self::hasInvalidSpaceBeforeEscapedClose($blade, $closeOffset) === true) {
                    $problem = self::problemOffsetForSpaceBeforeEscapedClose($blade, $closeOffset);
                    [$line, $col] = self::lineColAt($blade, $problem);
                    $violations[] = [
                        'line' => $line,
                        'column' => $col,
                        'code' => 'SpaceBeforeClosing',
                        'message' => 'Blade trimmed echo must have exactly one ASCII space before }} or -}}',
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
     * True when spacing after the opening delimiter is not exactly one ASCII space.
     *
     * @param int $openLen length of `{{` (2), or `{{-` / `{!!` (3)
     */
    private static function hasInvalidSpaceAfterOpening(string $blade, int $i, int $n, int $openLen): bool
    {
        $sp = $i + $openLen;
        if ($sp >= $n) {
            return true;
        }
        if ($blade[$sp] !== ' ') {
            return true;
        }

        return $sp + 1 < $n && $blade[$sp + 1] === ' ';
    }

    /**
     * True when spacing before `}}` / `-}}` is not exactly one ASCII space.
     */
    private static function hasInvalidSpaceBeforeEscapedClose(string $blade, int $close): bool
    {
        if ($close < 1) {
            return true;
        }
        if ($blade[$close - 1] === '-') {
            if ($close < 2) {
                return true;
            }
            if ($blade[$close - 2] !== ' ') {
                return true;
            }

            return $close >= 3 && $blade[$close - 3] === ' ';
        }

        if ($blade[$close - 1] !== ' ') {
            return true;
        }

        return $close >= 2 && $blade[$close - 2] === ' ';
    }

    /**
     * True when spacing before `!!}` is not exactly one ASCII space.
     */
    private static function hasInvalidSpaceBeforeUnescapedClose(string $blade, int $close): bool
    {
        if ($close < 1) {
            return true;
        }
        if ($blade[$close - 1] !== ' ') {
            return true;
        }

        return $close >= 2 && $blade[$close - 2] === ' ';
    }

    /**
     * Byte offset of the character to underline for an invalid opening delimiter (must pair with hasInvalidSpaceAfterOpening).
     */
    private static function problemOffsetForSpaceAfterOpening(string $blade, int $i, int $n, int $openLen): int
    {
        $sp = $i + $openLen;
        if ($sp >= $n) {
            return max(0, $n - 1);
        }

        if ($blade[$sp] !== ' ') {
            return $sp;
        }

        return $sp + 1;
    }

    /**
     * Byte offset for invalid spacing before `}}` / `-}}` ($close = index of first `}`).
     */
    private static function problemOffsetForSpaceBeforeEscapedClose(string $blade, int $close): int
    {
        if ($close < 1) {
            return 0;
        }

        if ($blade[$close - 1] === '-') {
            if ($close < 2) {
                return $close;
            }

            // Missing space before `-}}`, or doubled space before `-}}` (underline the space before `-`).
            return $close - 2;
        }

        if ($blade[$close - 1] !== ' ') {
            return $close;
        }

        return $close - 1;
    }

    /**
     * Byte offset for invalid spacing before `!!}` ($close = index of first `!`).
     */
    private static function problemOffsetForSpaceBeforeUnescapedClose(string $blade, int $close): int
    {
        if ($close < 1) {
            return 0;
        }

        if ($blade[$close - 1] !== ' ') {
            return $close;
        }

        return $close - 1;
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
