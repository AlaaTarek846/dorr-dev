<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSitePurchase extends Model
{
    protected $table = 'ai_site_purchases';

    protected $fillable = [
        'owner_type', 'owner_id', 'offer_id', 'amount', 'currency', 'wallet_operation_id',
        'generations_included', 'generations_used', 'status',
    ];

    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'amount' => 'float', 'generations_included' => 'integer', 'generations_used' => 'integer'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(AiSiteOffer::class, 'offer_id');
    }

    public function generationsLeft(): int
    {
        return max(0, $this->generations_included - $this->generations_used);
    }
}
