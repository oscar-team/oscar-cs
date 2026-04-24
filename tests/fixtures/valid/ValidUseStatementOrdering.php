<?php

namespace App\Example;

use Vendor\Alpha\FirstClass;
use Vendor\Beta\SecondClass;
use Vendor\Package\{ClassA, ClassB};

use function Vendor\Alpha\first_function;
use function Vendor\Beta\second_function;

use const Vendor\Alpha\FIRST_CONST;
use const Vendor\Beta\SECOND_CONST;

/**
 * Positive fixture: use statements are ordered by kind (class, function, const)
 * and alphabetically by fully-qualified name within each kind.
 */
final class ValidUseStatementOrdering
{
}
