<?php

namespace Modules\Wallet\Exceptions;

use Modules\Wallet\Enums\FinancialEntryType;
use RuntimeException;

class FinancialEntryTypeMismatchException extends RuntimeException
{
    public function __construct(string $categorySlug, FinancialEntryType $categoryType, FinancialEntryType $requestedType)
    {
        parent::__construct(
            "financial_categories '{$categorySlug}' is {$categoryType->value}, can't record a {$requestedType->value} entry against it.",
        );
    }
}
