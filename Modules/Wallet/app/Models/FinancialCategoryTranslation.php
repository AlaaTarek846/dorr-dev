<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialCategoryTranslation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['financial_category_id', 'locale', 'name'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'financial_category_id');
    }
}
