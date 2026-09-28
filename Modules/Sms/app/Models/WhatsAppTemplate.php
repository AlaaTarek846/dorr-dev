<?php

namespace Modules\Sms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class WhatsAppTemplate extends Model
{
    protected $fillable = [
        'whatsapp_id',
        'template_name',
        'language',
        'meta_status',
        'variables',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function whatsapp(): BelongsTo
    {
        return $this->belongsTo(WhatsApp::class);
    }

    /**
     * True only when Meta authoritatively approved the template.
     */
    public function isApproved(): bool
    {
        return $this->meta_status === 'approved';
    }
}
