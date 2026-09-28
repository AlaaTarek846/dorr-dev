<?php

namespace Modules\Sms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsApp extends Model
{
    protected $fillable = [
        'name',
        'access_token',
        'phone_number_id',
        'business_account_id',
        'api_version',
        'is_active',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'access_token',
        'business_account_id',
    ];

    public function countries(): HasMany
    {
        return $this->hasMany(WhatsAppCountry::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class);
    }

    public function testStatus(): string
    {
        return $this->test_status ?? 'never_tested';
    }
}
