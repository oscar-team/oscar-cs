<?php

declare(strict_types=1);

namespace Oscar\Sniffs\Blade;

use Oscar\Blade\FragmentExtractor;
use Oscar\Blade\SyntheticPhpBuilder;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Files\LocalFile;
use PHP_CodeSniffer\Sniffs\Sniff;

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
    ];

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
            $this->replayMessages($phpcsFile, $child->getErrors(), true);
            $this->replayMessages($phpcsFile, $child->getWarnings(), false);
        } finally {
            if (is_file($tmpPhp)) {
                unlink($tmpPhp);
            }
        }
    }

    /**
     * @param array<int, array<int, array<int, array<string, mixed>>>> $byLine
     */
    private function replayMessages(File $bladeFile, array $byLine, bool $isError): void
    {
        foreach ($byLine as $line => $columns) {
            $bladeLine = SyntheticPhpBuilder::childLineToBladeLine((int) $line);
            if ($bladeLine === null) {
                continue;
            }

            foreach ($columns as $entries) {
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

                    if ($isError === true) {
                        $bladeFile->addErrorOnLine($message, $bladeLine, $source);
                    } else {
                        $bladeFile->addWarningOnLine($message, $bladeLine, $source);
                    }
                }
            }
        }
    }
}
