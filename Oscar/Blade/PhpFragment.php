<?php

declare(strict_types=1);

namespace Oscar\Blade;

/**
 * A PHP snippet extracted from a Blade template, with source location in the Blade file.
 */
final class PhpFragment
{
    public function __construct(
        public int $startLine,
        public int $startColumn,
        public string $kind,
        public string $code,
        public ?string $directive = null,
    ) {}
}
