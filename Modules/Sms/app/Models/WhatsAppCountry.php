<?php

namespace Modules\Sms\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppCountry extends Model
{
    protected $table = 'whatsapp_countries';

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
        return $this->belongsTo(WhatsApp::class, 'whatsapp_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
