<?php

namespace Modules\Wallet\Exceptions;

use RuntimeException;

/**
 * No silent fallback (e.g. Jawad's hardcoded `category_id => 1`) — an
 * unknown slug is always a bug, and should fail loudly at the call site
 * during development, not quietly file the entry under the wrong category.
 */
class FinancialCategoryNotFoundException extends RuntimeException
{
    public function __construct(string $slug)
    {
        parent::__construct("No financial_categories row with slug '{$slug}'. Seed it first — see Modules\\Wallet\\Database\\Seeders\\FinancialCategorySeeder.");
    }
}
