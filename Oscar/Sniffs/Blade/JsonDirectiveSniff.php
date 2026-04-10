<?php

declare(strict_types=1);

namespace Oscar\Sniffs\Blade;

use Oscar\Blade\JsonDirectiveChecker;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Warns when @json(...) is used with an array literal, multiple top-level arguments, or a nested call with multiple arguments.
 */
final class JsonDirectiveSniff implements Sniff
{
    /**
     * @var array<string, true>
     */
    private static array $processed = [];

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

        foreach (JsonDirectiveChecker::check($content) as $v) {
            $phpcsFile->addWarningOnLine(
                $v['message'],
                $v['line'],
                'Oscar.Blade.JsonDirective.InvalidExpression',
            );
        }
    }
}
