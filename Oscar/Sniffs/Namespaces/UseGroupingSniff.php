<?php

declare(strict_types=1);

namespace Oscar\Sniffs\Namespaces;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Validates compound namespace use statements per PER Coding Style §3.
 */
final class UseGroupingSniff implements Sniff
{
    /**
     * {@inheritDoc}
     */
    public function register(): array
    {
        return [T_OPEN_USE_GROUP];
    }

    /**
     * {@inheritDoc}
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();
        $groupCloser = $tokens[$stackPtr]['bracket_closer'] ?? null;

        if ($groupCloser === null) {
            return;
        }

        $itemStart = $stackPtr + 1;
        while ($itemStart < $groupCloser) {
            $itemStart = $phpcsFile->findNext(Tokens::$emptyTokens, $itemStart, $groupCloser, true);
            if ($itemStart === false || $itemStart >= $groupCloser) {
                break;
            }

            $itemEnd = $phpcsFile->findNext([T_COMMA, T_CLOSE_USE_GROUP], $itemStart, $groupCloser + 1);
            if ($itemEnd === false) {
                $itemEnd = $groupCloser;
            }

            $aliasPtr = $phpcsFile->findNext(T_AS, $itemStart, $itemEnd);
            $nameEnd = $aliasPtr === false ? $itemEnd : $aliasPtr;

            $rawName = trim($phpcsFile->getTokensAsString($itemStart, $nameEnd - $itemStart));
            // Remove leading backslash if present.
            $rawName = ltrim($rawName, '\\');

            $segmentCount = substr_count($rawName, '\\');
            if ($segmentCount > 1) {
                $phpcsFile->addError(
                    'Grouped use statements MUST NOT contain entries with more than one namespace separator inside the group (PER 3.0 §3).',
                    $itemStart,
                    'TooManySegments'
                );
            }

            $itemStart = $itemEnd + 1;
        }
    }
}
