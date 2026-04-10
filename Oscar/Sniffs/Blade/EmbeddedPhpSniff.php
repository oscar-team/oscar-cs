<?php

declare(strict_types=1);

namespace Oscar\Sniffs\Blade;

use Oscar\Blade\FragmentExtractor;
use Oscar\Blade\SyntheticPhpBuilder;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Files\LocalFile;
use PHP_CodeSniffer\Sniffs\Sniff;
use ReflectionMethod;

/**
 * Delegates PHPCS to PHP extracted from Blade directives and replays violations on the Blade file.
 */
final class EmbeddedPhpSniff implements Sniff
{
    /**
     * Sniff codes that only apply to the temporary synthetic PHP buffer, not the Blade source.
     *
     * @var list<string>
     */
    private const SKIP_REPLAY_SOURCE_PREFIXES = [
        'PSR12.Files.FileHeader',
        'PSR2.Files.EndFileNewline',
        'Internal.',
        // Synthetic buffer joins multiple Blade echoes on one HTML line; not fixable in the Blade source as stated.
        'Generic.Formatting.DisallowMultipleStatements',
        // Indented Blade/HTML context; scope indent does not map to Blade columns.
        'Generic.WhiteSpace.ScopeIndent',
        // Opening brace placement on synthetic @if/@foreach stubs; not fixable in Blade as written.
        'Squiz.ControlStructures.ControlSignature.OpeningBrace',
        'Squiz.ControlStructures.ControlSignature.Line',
    ];

    /**
     * @var array<string, true>
     */
    private static array $processed = [];

    private static ?ReflectionMethod $fileAddMessage = null;

    private const SPACE_AFTER_KEYWORD = 'Squiz.ControlStructures.ControlSignature.SpaceAfterKeyword';

    private const OPERATOR_SPACING_PREFIX = 'PSR12.Operators.OperatorSpacing.';

    public function register(): array
    {
        return [
            T_INLINE_HTML => T_INLINE_HTML,
            T_OPEN_TAG => T_OPEN_TAG,
        ];
    }

    public function process(File $phpcsFile, $stackPtr): void
    {
        $path = $phpcsFile->getFilename();
        if (!str_ends_with($path, '.blade.php')) {
            return;
        }

        $real = realpath($path);
        if ($real === false) {
            return;
        }

        if (isset(self::$processed[$real]) === true) {
            return;
        }

        self::$processed[$real] = true;

        $content = file_get_contents($path);
        if ($content === false) {
            return;
        }

        $fragments = FragmentExtractor::extract($content);
        if ($fragments === []) {
            return;
        }

        $synthetic = SyntheticPhpBuilder::build($content, $fragments);
        $tmpPhp    = sys_get_temp_dir() . '/oscar-blade-' . bin2hex(random_bytes(8)) . '.php';

        file_put_contents($tmpPhp, $synthetic);

        try {
            $child = new LocalFile($tmpPhp, $phpcsFile->ruleset, $phpcsFile->config);
            $child->process();
            $this->replayMessages($phpcsFile, $content, $child->getErrors(), true);
            $this->replayMessages($phpcsFile, $content, $child->getWarnings(), false);
        } finally {
            if (is_file($tmpPhp)) {
                unlink($tmpPhp);
            }
        }
    }

    /**
     * @param array<int, array<int, array<int, array<string, mixed>>>> $byLine
     */
    private function replayMessages(File $bladeFile, string $bladeContent, array $byLine, bool $isError): void
    {
        $bladeLines = preg_split("/\r\n|\n|\r/", $bladeContent) ?: [];

        foreach ($byLine as $line => $columns) {
            $bladeLine = SyntheticPhpBuilder::childLineToBladeLine((int) $line);
            if ($bladeLine === null) {
                continue;
            }

            foreach ($columns as $childColumn => $entries) {
                foreach ($entries as $entry) {
                    $message = $entry['message'] ?? '';
                    $source  = $entry['source'] ?? '';
                    if ($message === '' || $source === '') {
                        continue;
                    }

                    foreach (self::SKIP_REPLAY_SOURCE_PREFIXES as $prefix) {
                        if (str_starts_with($source, $prefix) === true) {
                            continue 2;
                        }
                    }

                    $severity = (int) ($entry['severity'] ?? 0);
                    if ($severity === 0) {
                        $severity = 5;
                    }
                    $fixable = (bool) ($entry['fixable'] ?? false);

                    if ($source === self::SPACE_AFTER_KEYWORD) {
                        $bladeLineText = $bladeLines[$bladeLine - 1] ?? '';
                        $column        = self::bladeColumnForControlKeyword($bladeLineText);
                        self::invokeAddMessage($bladeFile, $isError, $message, $bladeLine, $column, $source, $severity, $fixable);
                    } elseif (str_starts_with($source, self::OPERATOR_SPACING_PREFIX) === true) {
                        $bladeLineText = $bladeLines[$bladeLine - 1] ?? '';
                        $syn           = SyntheticPhpBuilder::syntheticLineForBladeLine($bladeContent, $bladeLine);
                        $column        = self::bladeColumnFromSyntheticPosition(
                            $bladeLineText,
                            $syn,
                            (int) $childColumn,
                        );
                        self::invokeAddMessage($bladeFile, $isError, $message, $bladeLine, $column, $source, $severity, $fixable);
                    } elseif ($isError === true) {
                        $bladeFile->addErrorOnLine($message, $bladeLine, $source, [], $severity);
                    } else {
                        $bladeFile->addWarningOnLine($message, $bladeLine, $source, [], $severity);
                    }
                }
            }
        }
    }

    /**
     * Map a 1-based column on the delegated synthetic PHP line to a 1-based column on the Blade line.
     */
    private static function bladeColumnFromSyntheticPosition(string $bladeLine, string $syntheticLine, int $childColumn): int
    {
        if ($bladeLine === '' || $syntheticLine === '') {
            return 1;
        }

        $pos = $childColumn - 1;
        if ($pos < 0) {
            $pos = 0;
        }
        $synLen = strlen($syntheticLine);
        if ($pos >= $synLen) {
            $pos = $synLen - 1;
        }

        for ($window = 8; $window <= 160; $window += 8) {
            $start = max(0, $pos - $window + 1);
            $len   = min($window, $synLen - $start);
            if ($len < 1) {
                break;
            }

            $slice = substr($syntheticLine, $start, $len);
            if ($slice === '') {
                continue;
            }

            $at = strpos($bladeLine, $slice);
            if ($at !== false) {
                return $at + ($pos - $start) + 1;
            }
        }

        if (preg_match('/=>/', $bladeLine, $m, PREG_OFFSET_CAPTURE) === 1) {
            return $m[0][1] + 1;
        }

        return 1;
    }

    /**
     * 1-based column of the first control keyword on a Blade source line (for IDE underlines).
     */
    private static function bladeColumnForControlKeyword(string $bladeLine): int
    {
        // Alternation order: longer keywords first (elseif before if; foreach before for).
        if (preg_match('/\b(?:elseif|foreach|for|while|switch|if)\b/', $bladeLine, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return 1;
        }

        return (int) $m[0][1] + 1;
    }

    private static function invokeAddMessage(
        File $bladeFile,
        bool $isError,
        string $message,
        int $line,
        int $column,
        string $code,
        int $severity,
        bool $fixable
    ): void {
        if (self::$fileAddMessage === null) {
            self::$fileAddMessage = new ReflectionMethod(File::class, 'addMessage');
            self::$fileAddMessage->setAccessible(true);
        }

        self::$fileAddMessage->invoke($bladeFile, $isError, $message, $line, $column, $code, [], $severity, $fixable);
    }
}
