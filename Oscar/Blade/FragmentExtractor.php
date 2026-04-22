<?php

declare(strict_types=1);

namespace Oscar\Blade;

/**
 * Extracts PHP fragments from Blade template source for PHPCS delegation.
 */
final class FragmentExtractor
{
    /**
     * @return list<PhpFragment>
     */
    public static function extract(string $blade): array
    {
        $fragments = [];
        $n         = strlen($blade);
        $i         = 0;

        while ($i < $n) {
            // {{-- ... --}}
            if ($i + 4 <= $n && substr($blade, $i, 4) === '{{--') {
                $end = strpos($blade, '--}}', $i + 4);
                if ($end === false) {
                    break;
                }
                $i = $end + 4;
                continue;
            }

            // @verbatim ... @endverbatim
            if (self::isDirective($blade, $i, $n, 'verbatim')) {
                $endStart = self::findClosingDirectiveStart($blade, $n, $i, 'endverbatim');
                if ($endStart === null) {
                    break;
                }
                $i = $endStart + strlen('@endverbatim');
                continue;
            }

            // {!! ... !!}
            if ($i + 3 <= $n && substr($blade, $i, 3) === '{!!') {
                [$sl, $sc]     = self::lineColAt($blade, $i);
                $innerStart    = $i + 3;
                $closeOffset   = self::findClosingWithStringAware($blade, $innerStart, $n, '!!}');
                if ($closeOffset === null) {
                    break;
                }
                $inner = substr($blade, $innerStart, $closeOffset - $innerStart);
                $fragments[] = new PhpFragment($sl, $sc, 'unescaped', $inner, null);
                $i = $closeOffset + 3;
                continue;
            }

            // {{ ... }} (not comment)
            if ($i + 2 <= $n && substr($blade, $i, 2) === '{{' && ($i + 3 >= $n || $blade[$i + 2] !== '-')) {
                [$sl, $sc]  = self::lineColAt($blade, $i);
                $innerStart = $i + 2;
                if ($innerStart < $n && $blade[$innerStart] === '-') {
                    ++$innerStart;
                }
                $closeOffset = self::findClosingDoubleBrace($blade, $innerStart, $n);
                if ($closeOffset === null) {
                    break;
                }
                $inner = trim(substr($blade, $innerStart, $closeOffset - $innerStart));
                if ($inner !== '') {
                    $fragments[] = new PhpFragment($sl, $sc, 'echo', $inner, null);
                }
                $i = $closeOffset + 2;
                continue;
            }

            // Escaped @@
            if ($blade[$i] === '@' && ($i + 1 < $n && $blade[$i + 1] === '@')) {
                $i += 2;
                continue;
            }

            // @directives
            if ($blade[$i] === '@' && ($i === 0 || !ctype_alnum($blade[$i - 1]))) {
                $nameInfo = self::readDirectiveName($blade, $i + 1, $n);
                if ($nameInfo !== null) {
                    [$dirName, $afterName] = $nameInfo;
                    $dirLower              = strtolower($dirName);

                    if ($dirLower === 'php') {
                        $j = $afterName;
                        $j = self::skipWs($blade, $j, $n);
                        if ($j < $n && $blade[$j] === '(') {
                            [$sl, $sc] = self::lineColAt($blade, $i);
                            $closeParen = self::scanBalancedParens($blade, $j, $n);
                            if ($closeParen === null) {
                                break;
                            }
                            $inner = substr($blade, $j + 1, $closeParen - $j - 1);
                            $fragments[] = new PhpFragment($sl, $sc, 'php_inline', $inner, 'php');
                            $i = $closeParen + 1;
                            continue;
                        }

                        $endPhpStart = self::findClosingDirectiveStart($blade, $n, $i, 'endphp');
                        if ($endPhpStart === null) {
                            break;
                        }
                        $bodyStart = self::skipWs($blade, $afterName, $n);
                        $inner     = substr($blade, $bodyStart, $endPhpStart - $bodyStart);
                        foreach (self::splitPhpBlockBodyLines($blade, $bodyStart, $inner) as $frag) {
                            $fragments[] = $frag;
                        }
                        $i = $endPhpStart + strlen('@endphp');
                        continue;
                    }

                    $j = self::skipWs($blade, $afterName, $n);
                    if ($j < $n && $blade[$j] === '(') {
                        [$sl, $sc] = self::lineColAt($blade, $i);
                        $closeParen = self::scanBalancedParens($blade, $j, $n);
                        if ($closeParen === null) {
                            break;
                        }
                        $inner = substr($blade, $j + 1, $closeParen - $j - 1);
                        $fragments[] = new PhpFragment($sl, $sc, 'directive', $inner, $dirLower);
                        $i = $closeParen + 1;
                        continue;
                    }
                }
            }

            ++$i;
        }

        return $fragments;
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

    private static function skipWs(string $s, int $j, int $n): int
    {
        while ($j < $n && ($s[$j] === ' ' || $s[$j] === "\t" || $s[$j] === "\n" || $s[$j] === "\r")) {
            ++$j;
        }

        return $j;
    }

    /**
     * One {@see PhpFragment} per physical line inside a multi-line @php … @endphp body so delegated
     * PHPCS uses the same line numbers as the Blade source (and does not collapse the whole block
     * onto the @php line).
     *
     * @return list<PhpFragment>
     */
    private static function splitPhpBlockBodyLines(string $blade, int $bodyStart, string $inner): array
    {
        $out = [];
        $len = strlen($inner);
        $o   = 0;
        while ($o < $len) {
            $eol = strcspn($inner, "\r\n", $o);
            $raw = substr($inner, $o, $eol);
            $t   = trim($raw);
            if ($t !== '' && str_starts_with($t, '//') === false) {
                [$sl, $sc] = self::lineColAt($blade, $bodyStart + $o);
                $out[]     = new PhpFragment($sl, $sc, 'php_block', $raw, 'php');
            }
            $o += $eol;
            if ($o < $len && $inner[$o] === "\r") {
                ++$o;
            }
            if ($o < $len && $inner[$o] === "\n") {
                ++$o;
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: int}|null [name, offset after name]
     */
    private static function readDirectiveName(string $s, int $start, int $n): ?array
    {
        if ($start >= $n || !ctype_alpha($s[$start])) {
            return null;
        }
        $j = $start + 1;
        while ($j < $n && (ctype_alnum($s[$j]) || $s[$j] === '_')) {
            ++$j;
        }

        return [substr($s, $start, $j - $start), $j];
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
