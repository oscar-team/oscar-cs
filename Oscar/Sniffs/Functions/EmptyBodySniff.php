<?php

declare(strict_types=1);

namespace Oscar\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Requires empty method and function bodies to use the inline "{}" form per PER Coding Style §4.4.
 */
final class EmptyBodySniff implements Sniff
{
    /**
     * {@inheritDoc}
     */
    public function register(): array
    {
        return [T_FUNCTION];
    }

    /**
     * {@inheritDoc}
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        if (!isset($tokens[$stackPtr]['scope_opener'], $tokens[$stackPtr]['scope_closer'])) {
            return;
        }

        $opener = $tokens[$stackPtr]['scope_opener'];
        $closer = $tokens[$stackPtr]['scope_closer'];

        if ($this->hasBodyContent($tokens, $opener, $closer)) {
            return;
        }

        $this->assertInlineBraces($phpcsFile, $stackPtr, $opener, $closer);
    }

    /**
     * Determine if any non-whitespace content exists between the braces.
     *
     * @param array<int, array<string, mixed>> $tokens
     */
    private function hasBodyContent(array $tokens, int $opener, int $closer): bool
    {
        for ($ptr = $opener + 1; $ptr < $closer; $ptr++) {
            if ($tokens[$ptr]['code'] !== T_WHITESPACE) {
                return true;
            }
        }

        return false;
    }

    private function assertInlineBraces(File $phpcsFile, int $functionPtr, int $opener, int $closer): void
    {
        $tokens = $phpcsFile->getTokens();

        if ($tokens[$opener]['line'] !== $tokens[$closer]['line']) {
            $phpcsFile->addError(
                'Empty function and method bodies MUST be written inline as "{}" on the same line (PER 3.0 §4.4).',
                $opener,
                'BracesNotInline'
            );
        }

        $beforeOpenerPtr = $opener - 1;
        if ($tokens[$beforeOpenerPtr]['code'] !== T_WHITESPACE || $tokens[$beforeOpenerPtr]['content'] !== ' ') {
            $phpcsFile->addError(
                'An inline empty body MUST be separated from the signature by a single space before "{" (PER 3.0 §4.4).',
                $opener,
                'MissingSpaceBeforeBrace'
            );
        }

        if ($opener + 1 !== $closer) {
            $phpcsFile->addError(
                'Inline empty bodies MUST contain no whitespace between "{" and "}" (PER 3.0 §4.4).',
                $opener,
                'WhitespaceInsideInlineBody'
            );
        }
    }
}
