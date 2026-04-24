<?php

declare(strict_types=1);

namespace App\Fixtures\UseOrderingDeclare;

use Vendor\Decl\OneClass;

use function Vendor\Decl\fn_alpha;
use function Vendor\Decl\fn_beta;

use const Vendor\Decl\CONST_ALPHA;
use const Vendor\Decl\CONST_BETA;

/**
 * Positive: declare(strict_types=1) before namespace; class / function / const imports ordered.
 */
final class ValidUseStatementOrderingDeclareStrictTypes
{
}
