<?php

namespace Modules\Sms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsProviderCountry extends Model
{
    protected $fillable = [
        'sms_provider_id',
        'country_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function smsProvider(): BelongsTo
    {
        return $this->belongsTo(SmsProvider::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Country::class);
    }
}
