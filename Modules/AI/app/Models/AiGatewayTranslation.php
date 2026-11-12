<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of an AI gateway's name (ai_gateway_translations). */
class AiGatewayTranslation extends Model
{
    protected $fillable = ['ai_gateway_id', 'locale', 'name'];

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(AiGateway::class, 'ai_gateway_id');
    }
}
