<?php

declare(strict_types=1);

namespace App\Fixtures\UseOrderingFnsInvalid;

use function Vendor\Fns\zebra_function;
use function Vendor\Fns\alpha_function;

/**
 * Negative: function use imports are not sorted alphabetically.
 */
final class InvalidUseStatementOrderingFunctionImports
{
}
