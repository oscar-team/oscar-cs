<?php

declare(strict_types=1);

namespace App\Fixtures\UseOrderingDeclareInvalid;

use Vendor\Decl\OneClass;

use const Vendor\Decl\CONST_ALPHA;

use function Vendor\Decl\fn_alpha;

/**
 * Negative: declare present but constant imports appear before function imports.
 */
final class InvalidUseStatementOrderingDeclareKindOrder
{
}
