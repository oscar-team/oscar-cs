<?php

namespace App;

use Vendor\Package\{ClassA, ClassB};
use function Vendor\Package\{functionA};
use const Vendor\Package\{CONSTANT_A};

#[\Attribute]
class CompliantExample
{
    #[\Attribute]
    private string $value;

    public function __construct(
        #[\Attribute]
        string $value,
    ) {
        $this->value = $value;
    }

    public function inlineEmpty(): void {}

    public function arrow(): callable
    {
        return fn(int $x, int $y): int
            => $x + $y;
    }

    public function arrayExample(): array
    {
        return [
            'first' => 'value',
            'second' => 'value',
        ];
    }

    public function matchExample(string $value): string
    {
        return match ($value) {
            'one' => '1',
            'two' => '2',
            default => 'other',
        };
    }

    public function closureUse(): callable
    {
        return function (
            int $first,
            int $second,
        ) use (
            $first,
            $second,
        ): int {
            return $first + $second;
        };
    }
}
