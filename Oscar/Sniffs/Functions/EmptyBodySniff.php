<?php

declare(strict_types=1);

namespace Oscar\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Requires empty method and function bodies, and void methods whose only statement is a bare
 * return, to use the inline "{}" form per PER Coding Style §4.4.
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

        if (self::bodyContainsNonWhitespace($tokens, $opener, $closer) === true
            && self::isVoidBareReturnOnlyBody($phpcsFile, $stackPtr, $opener, $closer) === false
        ) {
            return;
        }

        $this->assertInlineBraces($phpcsFile, $opener, $closer);
    }

    /**
     * Same-line "{}" bodies allowed by PER §4.4 must not be flagged by PSR-12 Allman brace placement.
     */
    public static function permitsInlineEmptyOpeningBrace(File $phpcsFile, int $stackPtr): bool
    {
        $tokens = $phpcsFile->getTokens();
        if (isset($tokens[$stackPtr]['scope_opener'], $tokens[$stackPtr]['scope_closer']) === false) {
            return false;
        }

        $opener = $tokens[$stackPtr]['scope_opener'];
        $closer = $tokens[$stackPtr]['scope_closer'];
        if ($tokens[$opener]['line'] !== $tokens[$closer]['line']) {
            return false;
        }

        if (self::bodyContainsNonWhitespace($tokens, $opener, $closer) === false) {
            return true;
        }

        return self::isVoidBareReturnOnlyBody($phpcsFile, $stackPtr, $opener, $closer);
    }

    /**
     * @param array<int, array<string, mixed>> $tokens
     */
    private static function bodyContainsNonWhitespace(array $tokens, int $opener, int $closer): bool
    {
        for ($ptr = $opener + 1; $ptr < $closer; $ptr++) {
            if ($tokens[$ptr]['code'] !== T_WHITESPACE) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the declaration returns void and the body is only a bare return; (optional comments/whitespace).
     */
    private static function isVoidBareReturnOnlyBody(File $phpcsFile, int $stackPtr, int $opener, int $closer): bool
    {
        $props = $phpcsFile->getMethodProperties($stackPtr);
        if (isset($props['return_type']) === false || strtolower($props['return_type']) !== 'void') {
            return false;
        }

        $tokens = $phpcsFile->getTokens();

        $first = $phpcsFile->findNext(Tokens::$emptyTokens, ($opener + 1), $closer, true);
        if ($first === false || $tokens[$first]['code'] !== T_RETURN) {
            return false;
        }

        $afterReturn = $phpcsFile->findNext(Tokens::$emptyTokens, ($first + 1), $closer, true);
        if ($afterReturn === false || $tokens[$afterReturn]['code'] !== T_SEMICOLON) {
            return false;
        }

        $afterSemi = $phpcsFile->findNext(Tokens::$emptyTokens, ($afterReturn + 1), $closer, true);

        return $afterSemi === false;
    }

    private function assertInlineBraces(File $phpcsFile, int $opener, int $closer): void
    {
        $tokens = $phpcsFile->getTokens();

        if ($tokens[$opener]['line'] !== $tokens[$closer]['line']) {
            $phpcsFile->addError(
                'Empty function and method bodies MUST be written inline as "{}" on the same line (PER 3.0 §4.4).',
                $opener,
                'BracesNotInline'
            );
            return;
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
                'Inline "{}" bodies MUST not contain statements or whitespace between "{" and "}" (PER 3.0 §4.4).',
                $opener,
                'WhitespaceInsideInlineBody'
            );
        }
    }
}
