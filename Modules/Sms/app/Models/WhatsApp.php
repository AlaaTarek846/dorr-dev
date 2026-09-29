<?php

namespace Modules\Sms\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsApp extends Model
{
    protected $table = 'whatsapps';

    protected $fillable = [
        'name',
        'access_token',
        'phone_number_id',
        'phone_number',
        'phone_country_id',
        'business_account_id',
        'api_version',
        'is_active',
        'is_available',
        'last_tested_at',
        'test_status',
        'test_error',
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

    public function getConfigurationPlaintextAttribute(): array
    {
        return [
            'access_token' => $this->access_token ?? '',
            'phone_number_id' => $this->phone_number_id ?? '',
            'business_account_id' => $this->business_account_id ?? '',
            'api_version' => $this->api_version ?? 'v25.0',
        ];
    }

    public function phoneCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'phone_country_id');
    }

    public function countries(): HasMany
    {
        // The foreign key is explicit: Laravel infers `whats_app_id` from the
        // `WhatsApp` class name, which does not exist in the schema.
        return $this->hasMany(WhatsAppCountry::class, 'whatsapp_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_id');
    }

    public function testStatus(): string
    {
        return $this->test_status ?? 'never_tested';
    }

    /**
     * Templates may only be pushed to Meta once the account is active,
     * configured and its connection test passed.
     */
    public function isReadyForTemplates(): bool
    {
        return (bool) $this->is_active
            && $this->testStatus() === 'passed'
            && filled($this->business_account_id);
    }
}
