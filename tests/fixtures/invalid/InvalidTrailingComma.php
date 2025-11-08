<?php

namespace App;

function missingTrailingComma(
    int $first,
    int $second
): array {
    return [
        'first' => 'value',
        'second' => 'value'
    ];
}

function matchWithoutTrailingComma(string $value): string
{
    return match ($value) {
        'one' => '1',
        'two' => '2'
    };
}
