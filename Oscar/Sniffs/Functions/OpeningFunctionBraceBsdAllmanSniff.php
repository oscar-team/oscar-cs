<?php

declare(strict_types=1);

namespace Oscar\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Functions\OpeningFunctionBraceBsdAllmanSniff as GenericOpeningFunctionBraceBsdAllmanSniff;
use PHP_CodeSniffer\Standards\Squiz\Sniffs\Functions\MultiLineFunctionDeclarationSniff;

/**
 * PSR-12 Allman opening braces for single-line declarations only, with a PER §4.4 exception for inline "{}".
 *
 * {@see MultiLineFunctionDeclarationSniff} must handle {@code ) {} } on its own line after wrapped parameters.
 */
final class OpeningFunctionBraceBsdAllmanSniff extends GenericOpeningFunctionBraceBsdAllmanSniff
{
    /**
     * @var MultiLineFunctionDeclarationSniff|null
     */
    private $multiLineDeclarationSniff;

    /**
     * {@inheritDoc}
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();
        if (isset($tokens[$stackPtr]['parenthesis_opener']) === false) {
            return;
        }

        if ($this->multiLineDeclarationSniff === null) {
            $this->multiLineDeclarationSniff = new MultiLineFunctionDeclarationSniff();
        }

        $openBracket = $tokens[$stackPtr]['parenthesis_opener'];
        if ($this->multiLineDeclarationSniff->isMultiLineDeclaration($phpcsFile, $stackPtr, $openBracket, $tokens) === true) {
            return;
        }

        if (EmptyBodySniff::permitsInlineEmptyOpeningBrace($phpcsFile, $stackPtr) === true) {
            return;
        }

        parent::process($phpcsFile, $stackPtr);
    }
}
