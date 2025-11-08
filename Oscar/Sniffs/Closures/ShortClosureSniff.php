<?php

declare(strict_types=1);

namespace Oscar\Sniffs\Closures;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Enforces PER Coding Style §7.1 short closure formatting requirements.
 */
final class ShortClosureSniff implements Sniff
{
    /**
     * {@inheritDoc}
     */
    public function register(): array
    {
        return [T_FN];
    }

    /**
     * {@inheritDoc}
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        $this->assertNoSpaceAfterFn($phpcsFile, $stackPtr);

        $arrowPtr = $phpcsFile->findNext(T_FN_ARROW, $stackPtr + 1);
        if ($arrowPtr === false) {
            return;
        }

        $this->assertArrowPrecededByCorrectWhitespace($phpcsFile, $stackPtr, $arrowPtr);
        $this->assertArrowFollowedBySingleSpace($phpcsFile, $arrowPtr);
        $this->assertSemicolonSpacing($phpcsFile, $arrowPtr);
    }

    private function assertNoSpaceAfterFn(File $phpcsFile, int $fnPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $nextPtr = $fnPtr + 1;

        if ($tokens[$nextPtr]['code'] === T_WHITESPACE) {
            $phpcsFile->addError(
                'Short closures MUST NOT include whitespace between "fn" and the parameter list (PER 3.0 §7.1).',
                $fnPtr,
                'WhitespaceAfterFn'
            );
        }
    }

    private function assertArrowPrecededByCorrectWhitespace(File $phpcsFile, int $fnPtr, int $arrowPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $prevMeaningful = $phpcsFile->findPrevious(Tokens::$emptyTokens, $arrowPtr - 1, null, true);

        if ($prevMeaningful === false) {
            return;
        }

        $beforeArrowPtr = $arrowPtr - 1;
        $beforeArrow = $tokens[$beforeArrowPtr];

        if ($tokens[$arrowPtr]['line'] === $tokens[$prevMeaningful]['line']) {
            if ($beforeArrow['code'] !== T_WHITESPACE || $beforeArrow['content'] !== ' ') {
                $phpcsFile->addError(
                    'Short closure arrows MUST have exactly one space before "=>" when kept on the same line as the signature (PER 3.0 §7.1).',
                    $arrowPtr,
                    'ArrowSpacingBeforeSameLine'
                );
            }

            return;
        }

        if ($beforeArrow['code'] !== T_WHITESPACE || strpos($beforeArrow['content'], "\n") === false) {
            $phpcsFile->addError(
                'When the short closure expression is moved to the next line, "=>" must start that new line with indentation (PER 3.0 §7.1).',
                $arrowPtr,
                'ArrowNewlineIndent'
            );

            return;
        }

        $indent = substr($beforeArrow['content'], strrpos($beforeArrow['content'], "\n") + 1);
        if ($indent === '') {
            $phpcsFile->addError(
                'Short closure arrows placed on the next line MUST be indented (PER 3.0 §7.1).',
                $arrowPtr,
                'ArrowIndentWidth'
            );
        }
    }

    private function assertArrowFollowedBySingleSpace(File $phpcsFile, int $arrowPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $afterPtr = $arrowPtr + 1;

        if ($tokens[$afterPtr]['code'] !== T_WHITESPACE || $tokens[$afterPtr]['content'] !== ' ') {
            $phpcsFile->addError(
                'Short closure arrows MUST be followed by exactly one space before the expression (PER 3.0 §7.1).',
                $arrowPtr,
                'ArrowSpacingAfter'
            );
        }
    }

    private function assertSemicolonSpacing(File $phpcsFile, int $arrowPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $semicolonPtr = $phpcsFile->findNext(T_SEMICOLON, $arrowPtr + 1);

        if ($semicolonPtr === false) {
            return;
        }

        $beforeSemicolonPtr = $semicolonPtr - 1;
        if ($tokens[$beforeSemicolonPtr]['code'] === T_WHITESPACE) {
            $phpcsFile->addError(
                'The semicolon terminating a short closure MUST NOT be preceded by whitespace (PER 3.0 §7.1).',
                $semicolonPtr,
                'SemicolonWhitespace'
            );
        }
    }
}
