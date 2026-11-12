<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingWebhookEvent extends Model
{
    protected $fillable = ['provider', 'event_hash', 'payload', 'processed_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
