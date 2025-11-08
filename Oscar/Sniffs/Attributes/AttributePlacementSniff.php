<?php

declare(strict_types=1);

namespace Oscar\Sniffs\Attributes;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Enforces PER Coding Style attribute placement and spacing rules (section 12).
 */
final class AttributePlacementSniff implements Sniff
{
    /**
     * {@inheritDoc}
     */
    public function register(): array
    {
        return [T_ATTRIBUTE];
    }

    /**
     * {@inheritDoc}
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens   = $phpcsFile->getTokens();
        $attribute = $tokens[$stackPtr];
        $closer   = $attribute['attribute_closer'] ?? null;

        if ($closer === null) {
            return;
        }

        $this->assertNoSpaceAfterOpener($phpcsFile, $stackPtr);
        $this->assertNoSpaceBeforeCloser($phpcsFile, $closer);
        $this->assertArgumentsNotEmpty($phpcsFile, $stackPtr, $closer);
        $this->assertDocblockAdjacency($phpcsFile, $stackPtr);

        $nextMeaningful = $phpcsFile->findNext(Tokens::$emptyTokens, $closer + 1, null, true);
        if ($nextMeaningful === false) {
            return;
        }

        if ($tokens[$nextMeaningful]['code'] === T_ATTRIBUTE) {
            $this->assertStackedAttributesAreConsecutive($phpcsFile, $closer, $nextMeaningful);
            return;
        }

        $isParameterAttribute = $this->isParameterAttribute($phpcsFile, $stackPtr);
        if ($isParameterAttribute) {
            $this->assertParameterPlacement($phpcsFile, $stackPtr, $closer, $nextMeaningful);
            return;
        }

        $this->assertStructurePlacement($phpcsFile, $stackPtr, $closer, $nextMeaningful);
    }

    private function assertNoSpaceAfterOpener(File $phpcsFile, int $attributePtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $nextPtr = $attributePtr + 1;

        if ($tokens[$nextPtr]['code'] === T_WHITESPACE) {
            $phpcsFile->addError(
                'Attribute names MUST immediately follow "#[" with no intervening whitespace (PER 3.0 §12.1).',
                $attributePtr,
                'WhitespaceAfterOpener'
            );
        }

        $firstContent = $phpcsFile->findNext(Tokens::$emptyTokens, $attributePtr + 1, $tokens[$attributePtr]['attribute_closer'], true);
        if ($firstContent !== false && $tokens[$firstContent]['line'] !== $tokens[$attributePtr]['line']) {
            $phpcsFile->addError(
                'Attribute names MUST begin on the same line as the opening "#[" (PER 3.0 §12.1).',
                $attributePtr,
                'AttributeNameLine'
            );
        }
    }

    private function assertNoSpaceBeforeCloser(File $phpcsFile, int $closerPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $previousPtr = $closerPtr - 1;
        if ($previousPtr > 0 && $tokens[$previousPtr]['code'] === T_WHITESPACE) {
            $phpcsFile->addError(
                'The closing attribute bracket must follow the attribute name or ")" with no space (PER 3.0 §12.1).',
                $closerPtr,
                'WhitespaceBeforeCloser'
            );
        }
    }

    private function assertArgumentsNotEmpty(File $phpcsFile, int $attributePtr, int $closerPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $openParen = $phpcsFile->findNext(T_OPEN_PARENTHESIS, $attributePtr, $closerPtr);
        if ($openParen === false) {
            return;
        }

        $nextContent = $phpcsFile->findNext(Tokens::$emptyTokens, $openParen + 1, $closerPtr, true);
        if ($nextContent === false) {
            return;
        }

        if ($tokens[$nextContent]['code'] === T_CLOSE_PARENTHESIS) {
            $phpcsFile->addError(
                'Empty parentheses MUST be omitted for attributes with no arguments (PER 3.0 §12.1).',
                $attributePtr,
                'EmptyAttributeArguments'
            );
        }
    }

    private function assertDocblockAdjacency(File $phpcsFile, int $attributePtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $previous = $phpcsFile->findPrevious(Tokens::$emptyTokens, $attributePtr - 1, null, true);

        if ($previous !== false && $tokens[$previous]['code'] === T_DOC_COMMENT_CLOSE_TAG) {
            if ($tokens[$attributePtr]['line'] !== $tokens[$previous]['line'] + 1) {
                $phpcsFile->addError(
                    'Docblocks must be immediately followed by attributes without blank lines (PER 3.0 §12.2).',
                    $attributePtr,
                    'DocblockSpacing'
                );
            }
        }
    }

    private function assertStackedAttributesAreConsecutive(File $phpcsFile, int $currentCloser, int $nextAttributePtr): void
    {
        $tokens = $phpcsFile->getTokens();
        if ($tokens[$nextAttributePtr]['line'] !== $tokens[$currentCloser]['line'] + 1) {
            $phpcsFile->addError(
                'Separate attribute blocks MUST be on consecutive lines with no blank lines between them (PER 3.0 §12.2).',
                $nextAttributePtr,
                'StackedAttributeSpacing'
            );
        }
    }

    private function assertParameterPlacement(File $phpcsFile, int $attributePtr, int $closerPtr, int $targetPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        if ($tokens[$attributePtr]['line'] === $tokens[$targetPtr]['line']) {
            $whitespacePtr = $closerPtr + 1;
            if ($tokens[$whitespacePtr]['code'] !== T_WHITESPACE || $tokens[$whitespacePtr]['content'] !== ' ') {
                $phpcsFile->addError(
                    'Inline parameter attributes MUST be separated from the parameter by a single space (PER 3.0 §12.2).',
                    $attributePtr,
                    'ParameterInlineSpacing'
                );
            }
            return;
        }

        if ($tokens[$targetPtr]['line'] !== $tokens[$closerPtr]['line'] + 1) {
            $phpcsFile->addError(
                'Multiline parameter attributes MUST be on their own line immediately before the parameter (PER 3.0 §12.2).',
                $attributePtr,
                'ParameterMultilineSpacing'
            );
        }
    }

    private function assertStructurePlacement(File $phpcsFile, int $attributePtr, int $closerPtr, int $targetPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        if ($tokens[$targetPtr]['line'] === $tokens[$attributePtr]['line']) {
            $phpcsFile->addError(
                'Attributes on declarations MUST be placed on their own line immediately before the declaration (PER 3.0 §12.2).',
                $attributePtr,
                'InlineStructureAttribute'
            );
            return;
        }

        if ($tokens[$targetPtr]['line'] !== $tokens[$closerPtr]['line'] + 1) {
            $phpcsFile->addError(
                'Attributes MUST be directly followed by the decorated declaration with no blank lines (PER 3.0 §12.2).',
                $attributePtr,
                'StructureSpacing'
            );
        }
    }

    private function isParameterAttribute(File $phpcsFile, int $attributePtr): bool
    {
        $tokens = $phpcsFile->getTokens();
        $previous = $phpcsFile->findPrevious(Tokens::$emptyTokens, $attributePtr - 1, null, true);
        if ($previous === false) {
            return false;
        }

        return in_array($tokens[$previous]['code'], [T_OPEN_PARENTHESIS, T_COMMA], true);
    }
}
