<?php

namespace Modules\Sms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppCountry extends Model
{
    protected $fillable = [
        'whatsapp_id',
        'country_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function whatsapp(): BelongsTo
    {
        return $this->belongsTo(WhatsApp::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Country::class);
    }
}
