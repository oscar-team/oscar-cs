<?php

declare(strict_types=1);

namespace Oscar\Sniffs\Blade;

use Oscar\Blade\EchoSpacingChecker;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use ReflectionMethod;

/**
 * Enforces compact or single-spaced Blade echoes (rejects multiple spaces) for `{{`, `{{-`, `{!!` and closing delimiters.
 */
final class EchoSpacingSniff implements Sniff
{
    /**
     * @var array<string, true>
     */
    private static array $processed = [];

    private static ?ReflectionMethod $fileAddMessage = null;

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
            self::invokeAddMessage(
                $phpcsFile,
                true,
                $v['message'],
                $v['line'],
                $v['column'],
                $code,
                5,
                false,
            );
        }
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
