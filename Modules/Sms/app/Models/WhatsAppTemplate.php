<?php

namespace Modules\Sms\Models;

use App\Models\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'whatsapp_id',
        'language_id',
        'template_name',
        'category',
        'meta_template_id',
        'meta_language',
        'components',
        'body',
        'meta_status',
        'is_active',
        'last_synced_at',
        'last_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function whatsapp(): BelongsTo
    {
        return $this->belongsTo(WhatsApp::class, 'whatsapp_id');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function isApproved(): bool
    {
        return $this->meta_status === 'approved';
    }

    public function metaLanguageCode(): string
    {
        return $this->meta_language ?? $this->language?->code ?? 'en';
    }
}
