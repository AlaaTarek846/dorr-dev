<?php

namespace Modules\Wallet\Enums;

enum FinancialEntryType: string
{
    case Income = 'income';
    case Expense = 'expense';
}
