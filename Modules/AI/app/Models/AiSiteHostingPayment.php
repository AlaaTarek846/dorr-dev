<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSiteHostingPayment extends Model
{
    protected $table = 'ai_site_hosting_payments';

    protected $fillable = ['hosting_id', 'kind', 'amount', 'currency', 'wallet_operation_id', 'period_start', 'period_end'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'period_start' => 'datetime', 'period_end' => 'datetime'];
    }

    public function hosting(): BelongsTo
    {
        return $this->belongsTo(AiSiteHosting::class, 'hosting_id');
    }
}
