<?php

declare(strict_types=1);

namespace Oscar\Sniffs\Blade;

use Oscar\Blade\EchoSpacingChecker;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Enforces exactly one space after `{{`, `{{-`, `{!!` and exactly one space before `}}`, `-}}`, `!!}` in Blade templates.
 */
final class EchoSpacingSniff implements Sniff
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

        foreach (EchoSpacingChecker::check($content) as $v) {
            $code = 'Oscar.Blade.EchoSpacing.' . $v['code'];
            $phpcsFile->addErrorOnLine($v['message'], $v['line'], $code);
        }
    }
}
