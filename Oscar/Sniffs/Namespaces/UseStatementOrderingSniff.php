<?php

declare(strict_types=1);

namespace Oscar\Sniffs\Namespaces;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Ensures namespace import use statements are ordered alphabetically by fully-qualified name,
 * with class imports before function imports before constant imports (PER Coding Style §3).
 */
final class UseStatementOrderingSniff implements Sniff
{
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
        $usePtr = 0;
        $prevNs = null;
        $lastKey = null;

        while (($usePtr = $phpcsFile->findNext(T_USE, $usePtr + 1)) !== false) {
            if ($this->shouldIgnoreUse($phpcsFile, $usePtr) === true) {
                continue;
            }

            $names = $this->collectImportedNames($phpcsFile, $usePtr);
            if ($names === []) {
                continue;
            }

            $kind = $this->getUseKind($phpcsFile, $usePtr);
            $minName = $names[0];
            foreach ($names as $name) {
                if (strcmp($name, $minName) < 0) {
                    $minName = $name;
                }
            }

            $key = $kind . "\0" . $minName;

            $ns = $phpcsFile->findPrevious(T_NAMESPACE, $usePtr);
            if ($ns !== $prevNs) {
                $lastKey = null;
                $prevNs = $ns;
            }

            if ($lastKey !== null && strcmp($key, $lastKey) < 0) {
                $phpcsFile->addError(
                    'Use statements must be sorted alphabetically by fully-qualified name; '
                    . 'declare class imports before function imports before constant imports (PER Coding Style §3).',
                    $usePtr,
                    'OutOfOrder'
                );
            }

            for ($i = 1, $max = count($names); $i < $max; $i++) {
                if (strcmp($names[$i - 1], $names[$i]) > 0) {
                    $phpcsFile->addError(
                        'Symbols in a grouped use statement must be sorted alphabetically by path (PER Coding Style §3).',
                        $usePtr,
                        'GroupedUsesOutOfOrder'
                    );
                    break;
                }
            }

            $lastKey = $key;
        }

        return $phpcsFile->numTokens;
    }

    /**
     * @return list<string> Fully-qualified names in source order
     */
    private function collectImportedNames(File $phpcsFile, int $usePtr): array
    {
        $nameStart = $this->firstNameTokenPtr($phpcsFile, $usePtr);
        if ($nameStart === false) {
            return [];
        }

        $semicolon = $phpcsFile->findNext([T_SEMICOLON], $usePtr + 1);
        if ($semicolon === false) {
            return [];
        }

        $groupOpen = $this->findGroupedUseBraceOpen($phpcsFile, $nameStart, $semicolon);
        if ($groupOpen !== false) {
            $base = trim($phpcsFile->getTokensAsString($nameStart, $groupOpen - $nameStart));
            $base = $this->normalizeFqcn($base);
            $groupClose = $this->findGroupedUseBraceClose($phpcsFile, $groupOpen, $semicolon);
            if ($groupClose === false) {
                return [];
            }

            $ranges = $this->splitUseGroupItemRanges($phpcsFile, $groupOpen, $groupClose);
            $out = [];
            foreach ($ranges as [$from, $to]) {
                $fqcn = $this->fqcnFromGroupItemRange($phpcsFile, $from, $to, $base);
                if ($fqcn !== '') {
                    $out[] = $fqcn;
                }
            }

            return $out;
        }

        $asPtr = $phpcsFile->findNext(T_AS, $nameStart, $semicolon);
        $nameEnd = $asPtr === false ? ($semicolon - 1) : ($asPtr - 1);
        $raw = trim($phpcsFile->getTokensAsString($nameStart, $nameEnd - $nameStart + 1));

        return $raw === '' ? [] : [$this->normalizeFqcn($raw)];
    }

    /**
     * PHP_CodeSniffer only maps `{` to T_OPEN_USE_GROUP when it follows T_NS_SEPARATOR; with PHP 8
     * T_NAME_QUALIFIED the brace stays T_OPEN_CURLY_BRACKET. Treat both as grouped use openers.
     */
    private function findGroupedUseBraceOpen(File $phpcsFile, int $nameStart, int $semicolon): int|false
    {
        $tokens = $phpcsFile->getTokens();
        $ptr = $phpcsFile->findNext([T_OPEN_USE_GROUP, T_OPEN_CURLY_BRACKET], $nameStart, $semicolon);
        if ($ptr === false) {
            return false;
        }

        if ($tokens[$ptr]['code'] === T_OPEN_USE_GROUP) {
            return $ptr;
        }

        $prev = $phpcsFile->findPrevious(Tokens::$emptyTokens, $ptr - 1, $nameStart - 1, true);
        if ($prev === false) {
            return false;
        }

        $allowed = [
            T_STRING => true,
            T_NS_SEPARATOR => true,
            T_NAME_QUALIFIED => true,
            T_NAME_FULLY_QUALIFIED => true,
        ];

        return isset($allowed[$tokens[$prev]['code']]) === true ? $ptr : false;
    }

    private function findGroupedUseBraceClose(File $phpcsFile, int $groupOpen, int $semicolon): int|false
    {
        $tokens = $phpcsFile->getTokens();
        if (isset($tokens[$groupOpen]['bracket_closer']) === true) {
            return $tokens[$groupOpen]['bracket_closer'];
        }

        if ($tokens[$groupOpen]['code'] === T_OPEN_USE_GROUP) {
            return $phpcsFile->findNext(T_CLOSE_USE_GROUP, $groupOpen + 1, $semicolon + 1);
        }

        return $phpcsFile->findNext(T_CLOSE_CURLY_BRACKET, $groupOpen + 1, $semicolon + 1);
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function splitUseGroupItemRanges(File $phpcsFile, int $groupOpen, int $groupClose): array
    {
        $tokens = $phpcsFile->getTokens();
        $ranges = [];
        $start = $groupOpen + 1;
        for ($i = $groupOpen + 1; $i < $groupClose; $i++) {
            if ($tokens[$i]['code'] === T_COMMA) {
                $ranges[] = [$start, $i - 1];
                $start = $i + 1;
            }
        }

        $ranges[] = [$start, $groupClose - 1];

        return $ranges;
    }

    private function fqcnFromGroupItemRange(File $phpcsFile, int $from, int $to, string $base): string
    {
        $asPtr = $phpcsFile->findNext(T_AS, $from, $to + 1);
        $end = $asPtr === false ? $to : ($asPtr - 1);
        $raw = trim($phpcsFile->getTokensAsString($from, $end - $from + 1));
        if ($raw === '') {
            return $base;
        }

        $segment = $this->normalizeFqcn($raw);
        if ($segment === '') {
            return $base;
        }

        if ($base === '') {
            return $segment;
        }

        $base = rtrim($base, '\\');
        $segment = ltrim($segment, '\\');

        return $this->normalizeFqcn($base . '\\' . $segment);
    }

    private function normalizeFqcn(string $name): string
    {
        return ltrim($name, '\\');
    }

    private function getUseKind(File $phpcsFile, int $usePtr): int
    {
        $tokens = $phpcsFile->getTokens();
        $pos = $phpcsFile->findNext(Tokens::$emptyTokens, $usePtr + 1, null, true);
        if ($pos === false) {
            return 0;
        }

        if ($tokens[$pos]['code'] === T_FUNCTION
            || ($tokens[$pos]['code'] === T_STRING && $tokens[$pos]['content'] === 'function')
        ) {
            return 1;
        }

        if ($tokens[$pos]['code'] === T_CONST
            || ($tokens[$pos]['code'] === T_STRING && $tokens[$pos]['content'] === 'const')
        ) {
            return 2;
        }

        return 0;
    }

    private function firstNameTokenPtr(File $phpcsFile, int $usePtr): int|false
    {
        $pos = $phpcsFile->findNext(Tokens::$emptyTokens, $usePtr + 1, null, true);
        if ($pos === false) {
            return false;
        }

        if ($this->getUseKind($phpcsFile, $usePtr) !== 0) {
            $pos = $phpcsFile->findNext(Tokens::$emptyTokens, $pos + 1, null, true);
        }

        return $pos;
    }

    private function shouldIgnoreUse(File $phpcsFile, int $stackPtr): bool
    {
        $tokens = $phpcsFile->getTokens();
        $next = $phpcsFile->findNext(Tokens::$emptyTokens, $stackPtr + 1, null, true);
        if ($next === false || $tokens[$next]['code'] === T_OPEN_PARENTHESIS) {
            return true;
        }

        return $phpcsFile->hasCondition($stackPtr, [T_CLASS, T_TRAIT, T_ENUM]) === true;
    }
}
