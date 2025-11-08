<?php

declare(strict_types=1);

namespace OscarStandard\Sniffs\Files;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Ensures file headers comply with PER Coding Style section 3 requirements for declare statements.
 */
final class StrictTypesDeclarationSniff implements Sniff
{
    /**
     * Whether multiple declare statements are allowed before the namespace declaration.
     *
     * @var bool
     */
    public $allowMultipleDeclare = false;

    /**
     * {@inheritDoc}
     */
    public function register(): array
    {
        return [T_OPEN_TAG];
    }

    /**
     * {@inheritDoc}
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        $firstOpenTag = $phpcsFile->findNext(T_OPEN_TAG, 0);
        if ($stackPtr !== $firstOpenTag) {
            return $phpcsFile->numTokens;
        }

        $declarePtr = $this->findStrictTypesDeclare($phpcsFile, $stackPtr);
        if ($declarePtr === null) {
            $phpcsFile->addError(
                'Files MUST declare strict_types=1 immediately after the opening tag or file-level docblock (PER 3.0 §3).',
                $stackPtr,
                'MissingStrictTypes'
            );

            return $phpcsFile->numTokens;
        }

        $this->assertDeclareFormat($phpcsFile, $declarePtr);

        if ($this->allowMultipleDeclare === false) {
            $this->assertNoAdditionalDeclares($phpcsFile, $declarePtr);
        }

        $this->assertSpacingAfterDeclare($phpcsFile, $declarePtr);

        return $phpcsFile->numTokens;
    }

    /**
     * Find the strict types declare statement following the opening PHP tag.
     */
    private function findStrictTypesDeclare(File $phpcsFile, int $openTagPtr): ?int
    {
        $tokens = $phpcsFile->getTokens();
        $searchPtr = $openTagPtr + 1;

        while ($searchPtr < $phpcsFile->numTokens) {
            $searchPtr = $phpcsFile->findNext(Tokens::$emptyTokens, $searchPtr, null, true);
            if ($searchPtr === false) {
                return null;
            }

            $code = $tokens[$searchPtr]['code'];

            if ($code === T_DECLARE) {
                return $searchPtr;
            }

            if ($code === T_DOC_COMMENT_OPEN_TAG) {
                $searchPtr = $tokens[$searchPtr]['comment_closer'] + 1;
                continue;
            }

            if (in_array(
                $code,
                [T_NAMESPACE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION],
                true
            )) {
                break;
            }

            if ($code !== T_DECLARE && $code !== T_COMMENT) {
                break;
            }

            $searchPtr++;
        }

        return null;
    }

    /**
     * Ensure the declare statement uses the exact allowed format.
     */
    private function assertDeclareFormat(File $phpcsFile, int $declarePtr): void
    {
        $tokens = $phpcsFile->getTokens();

        $nextPtr = $declarePtr + 1;
        if ($tokens[$nextPtr]['code'] === T_WHITESPACE) {
            $phpcsFile->addError(
                'declare(strict_types=1) MUST NOT contain spaces after the declare keyword (PER 3.0 §3).',
                $declarePtr,
                'SpacingAfterDeclare'
            );
        }

        $openParen = $phpcsFile->findNext(T_OPEN_PARENTHESIS, $declarePtr + 1);
        if ($openParen === false || $openParen !== $phpcsFile->findNext(Tokens::$emptyTokens, $declarePtr + 1, null, true)) {
            $phpcsFile->addError(
                'declare(strict_types=1) MUST open its parentheses immediately after the keyword (PER 3.0 §3).',
                $declarePtr,
                'MissingOpenParenthesis'
            );

            return;
        }

        $closeParen = $tokens[$openParen]['parenthesis_closer'] ?? null;
        if ($closeParen === null) {
            return;
        }

        $declareContent = trim($phpcsFile->getTokensAsString($openParen + 1, ($closeParen - $openParen - 1)));
        if ($declareContent !== 'strict_types=1') {
            $phpcsFile->addError(
                'declare statements MUST be exactly declare(strict_types=1) with no spaces (PER 3.0 §3).',
                $declarePtr,
                'StrictTypesValue'
            );
        }

        $prevPtr = $closeParen - 1;
        if ($tokens[$prevPtr]['code'] === T_WHITESPACE) {
            $phpcsFile->addError(
                'Whitespace is not allowed before the closing parenthesis in declare(strict_types=1) (PER 3.0 §3).',
                $closeParen,
                'WhitespaceBeforeCloser'
            );
        }
    }

    /**
     * Ensure no additional declare statements appear before the namespace declaration.
     */
    private function assertNoAdditionalDeclares(File $phpcsFile, int $firstDeclarePtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $namespacePtr = $phpcsFile->findNext(T_NAMESPACE, $firstDeclarePtr + 1);

        $nextDeclare = $phpcsFile->findNext(
            T_DECLARE,
            $firstDeclarePtr + 1,
            $namespacePtr === false ? null : $namespacePtr
        );

        if ($nextDeclare !== false) {
            $phpcsFile->addError(
                'Only a single declare(strict_types=1) statement is allowed before the namespace declaration (PER 3.0 §3).',
                $nextDeclare,
                'MultipleDeclares'
            );
        }
    }

    /**
     * Ensure there is exactly one blank line after the declare statement block.
     */
    private function assertSpacingAfterDeclare(File $phpcsFile, int $declarePtr): void
    {
        $tokens = $phpcsFile->getTokens();

        $openParen = $phpcsFile->findNext(T_OPEN_PARENTHESIS, $declarePtr + 1);
        if ($openParen === false) {
            return;
        }

        $closeParen = $tokens[$openParen]['parenthesis_closer'] ?? null;
        if ($closeParen === null) {
            return;
        }

        $endPtr = $closeParen;
        $nextPtr = $phpcsFile->findNext(Tokens::$emptyTokens, $closeParen + 1, null, true);
        if ($nextPtr !== false && $tokens[$nextPtr]['code'] === T_SEMICOLON) {
            $endPtr = $nextPtr;
            $nextPtr = $phpcsFile->findNext(Tokens::$emptyTokens, $nextPtr + 1, null, true);
        }

        if ($nextPtr === false) {
            return;
        }

        $lineDiff = $tokens[$nextPtr]['line'] - $tokens[$endPtr]['line'];
        if ($lineDiff !== 2) {
            $phpcsFile->addError(
                'Exactly one blank line MUST separate declare(strict_types=1) from the following block (PER 3.0 §3).',
                $declarePtr,
                'DeclareSpacing'
            );
        }
    }
}
