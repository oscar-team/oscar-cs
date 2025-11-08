<?php

declare(strict_types=1);

namespace OscarStandard\Sniffs\Formatting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Enforces PER Coding Style §2.6 trailing comma requirements for arrays, argument lists, and parameter lists.
 */
final class TrailingCommaSniff implements Sniff
{
    /**
     * Tokens that indicate the opening parenthesis belongs to a function/method/call-like list.
     *
     * @var array<int|string, bool>
     */
    private const CALLABLE_PRECEDERS = [
        T_STRING                         => true,
        T_VARIABLE                       => true,
        T_CLOSE_PARENTHESIS              => true,
        T_CLOSE_CURLY_BRACKET            => true,
        T_CLOSE_SQUARE_BRACKET           => true,
        T_CLOSE_SHORT_ARRAY              => true,
        T_STATIC                         => true,
        T_SELF                           => true,
        T_PARENT                         => true,
        T_NEW                            => true,
        T_FN                             => true,
        T_ISSET                          => true,
        T_EMPTY                          => true,
        T_UNSET                          => true,
        T_REQUIRE                        => true,
        T_REQUIRE_ONCE                   => true,
        T_INCLUDE                        => true,
        T_INCLUDE_ONCE                   => true,
        T_EVAL                           => true,
        T_NAME_FULLY_QUALIFIED           => true,
        T_NAME_QUALIFIED                 => true,
        T_NAME_RELATIVE                  => true,
    ];

    /**
     * Tokens that should be skipped when searching for meaningful content.
     *
     * @var array<int|string, int|string>|null
     */
    private static $skippableTokens;

    /**
     * {@inheritDoc}
     */
    public function register(): array
    {
        return [
            T_ARRAY,
            T_OPEN_SHORT_ARRAY,
            T_FUNCTION,
            T_FN,
            T_OPEN_PARENTHESIS,
            T_USE,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();
        $code   = $tokens[$stackPtr]['code'];

        switch ($code) {
            case T_ARRAY:
                $this->processArray($phpcsFile, $stackPtr);
                return;

            case T_OPEN_SHORT_ARRAY:
                $this->processShortArray($phpcsFile, $stackPtr);
                return;

            case T_FUNCTION:
                $this->processFunctionDeclaration($phpcsFile, $stackPtr);
                return;

            case T_FN:
                $this->processShortClosureParameters($phpcsFile, $stackPtr);
                return;

            case T_OPEN_PARENTHESIS:
                $this->processParenthesis($phpcsFile, $stackPtr);
                return;

            case T_USE:
                $this->processClosureUse($phpcsFile, $stackPtr);
                return;
        }
    }

    private function processArray(File $phpcsFile, int $arrayPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        if (!isset($tokens[$arrayPtr]['parenthesis_opener'])) {
            return;
        }

        $open  = $tokens[$arrayPtr]['parenthesis_opener'];
        $close = $tokens[$open]['parenthesis_closer'] ?? null;
        if ($close === null) {
            return;
        }

        $this->checkList($phpcsFile, $open, $close, 'Array');
    }

    private function processShortArray(File $phpcsFile, int $openPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $close  = $tokens[$openPtr]['bracket_closer'] ?? null;
        if ($close === null) {
            return;
        }

        $this->checkList($phpcsFile, $openPtr, $close, 'Array');
    }

    private function processFunctionDeclaration(File $phpcsFile, int $functionPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        if (!isset($tokens[$functionPtr]['parenthesis_opener'])) {
            return;
        }

        $open  = $tokens[$functionPtr]['parenthesis_opener'];
        $close = $tokens[$open]['parenthesis_closer'] ?? null;
        if ($close === null) {
            return;
        }

        $this->checkList($phpcsFile, $open, $close, 'ParameterList');
    }

    private function processShortClosureParameters(File $phpcsFile, int $fnPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        if (!isset($tokens[$fnPtr]['parenthesis_opener'])) {
            return;
        }

        $open  = $tokens[$fnPtr]['parenthesis_opener'];
        $close = $tokens[$open]['parenthesis_closer'] ?? null;
        if ($close === null) {
            return;
        }

        $this->checkList($phpcsFile, $open, $close, 'ParameterList');
    }

    private function processParenthesis(File $phpcsFile, int $openPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $close  = $tokens[$openPtr]['parenthesis_closer'] ?? null;
        if ($close === null) {
            return;
        }

        $prevPtr = $phpcsFile->findPrevious(self::getSkippableTokens(), $openPtr - 1, null, true);
        if ($prevPtr === false) {
            return;
        }

        $prevCode = $tokens[$prevPtr]['code'];

        if ($prevCode === T_FUNCTION || $prevCode === T_FN || $prevCode === T_ARRAY) {
            // Handled via specific processors.
            return;
        }

        if (!isset(self::CALLABLE_PRECEDERS[$prevCode])) {
            return;
        }

        $this->checkList($phpcsFile, $openPtr, $close, 'ArgumentList');
    }

    private function processClosureUse(File $phpcsFile, int $usePtr): void
    {
        $tokens = $phpcsFile->getTokens();

        if (!isset($tokens[$usePtr]['parenthesis_opener'])) {
            // Namespace use statement.
            return;
        }

        $open  = $tokens[$usePtr]['parenthesis_opener'];
        $close = $tokens[$open]['parenthesis_closer'] ?? null;
        if ($close === null) {
            return;
        }

        $this->checkList($phpcsFile, $open, $close, 'UseList');
    }

    private function checkList(File $phpcsFile, int $openPtr, int $closePtr, string $context): void
    {
        $tokens = $phpcsFile->getTokens();

        if ($tokens[$openPtr]['line'] === $tokens[$closePtr]['line']) {
            $this->ensureNoTrailingCommaOnSingleLine($phpcsFile, $openPtr, $closePtr, $context);
            return;
        }

        $firstContent = $phpcsFile->findNext(self::getSkippableTokens(), $openPtr + 1, $closePtr, true);
        if ($firstContent === false) {
            // Empty multi-line list; nothing to enforce.
            return;
        }

        $lastContent = $this->findLastMeaningful($phpcsFile, $openPtr, $closePtr);
        if ($lastContent === null) {
            return;
        }

        if ($tokens[$lastContent]['code'] === T_COMMA) {
            return;
        }

        $fix = $phpcsFile->addFixableError(
            sprintf('%s items spread across multiple lines MUST end with a trailing comma (PER 3.0 §2.6).', $context),
            $lastContent,
            $context . 'MissingTrailingComma'
        );

        if ($fix === true) {
            $phpcsFile->fixer->beginChangeset();
            $phpcsFile->fixer->addContent($lastContent, ',');
            $phpcsFile->fixer->endChangeset();
        }
    }

    private function ensureNoTrailingCommaOnSingleLine(File $phpcsFile, int $openPtr, int $closePtr, string $context): void
    {
        $lastContent = $this->findLastMeaningful($phpcsFile, $openPtr, $closePtr);
        if ($lastContent === null) {
            return;
        }

        $tokens = $phpcsFile->getTokens();
        if ($tokens[$lastContent]['code'] !== T_COMMA) {
            return;
        }

        $fix = $phpcsFile->addFixableError(
            sprintf('%s contained on a single line MUST NOT include a trailing comma (PER 3.0 §2.6).', $context),
            $lastContent,
            $context . 'UnexpectedTrailingComma'
        );

        if ($fix === true) {
            $phpcsFile->fixer->beginChangeset();
            $phpcsFile->fixer->replaceToken($lastContent, '');
            $phpcsFile->fixer->endChangeset();
        }
    }

    private function findLastMeaningful(File $phpcsFile, int $openPtr, int $closePtr): ?int
    {
        $last = $phpcsFile->findPrevious(self::getSkippableTokens(), $closePtr - 1, $openPtr + 1, true);
        if ($last === false) {
            return null;
        }

        return $last;
    }

    /**
     * @return array<int|string, int|string>
     */
    private static function getSkippableTokens(): array
    {
        if (self::$skippableTokens !== null) {
            return self::$skippableTokens;
        }

        self::$skippableTokens = Tokens::$emptyTokens;

        $commentTokens = [
            T_COMMENT,
            T_DOC_COMMENT,
            T_DOC_COMMENT_STAR,
            T_DOC_COMMENT_WHITESPACE,
            T_DOC_COMMENT_TAG,
            T_DOC_COMMENT_OPEN_TAG,
            T_DOC_COMMENT_CLOSE_TAG,
            T_DOC_COMMENT_STRING,
        ];

        foreach ($commentTokens as $token) {
            self::$skippableTokens[$token] = $token;
        }

        return self::$skippableTokens;
    }
}
