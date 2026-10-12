<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiSiteServiceRequest extends Model
{
    protected $table = 'ai_site_service_requests';

    protected $fillable = [
        'reference', 'owner_type', 'owner_id', 'title', 'site_type', 'status', 'brief', 'files', 'country_id', 'currency',
        'quote_amount', 'quote_duration_days', 'quote_valid_until', 'quote_notes', 'quote_sent_at', 'decline_reason',
        'accepted_at', 'started_at', 'due_at', 'delivered_at', 'completed_at',
        'admin_notes', 'delivery_url', 'delivery_notes', 'admin_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'brief' => 'array',
            'files' => 'array',
            'owner_id' => 'integer',
            'country_id' => 'integer',
            'quote_amount' => 'float',
            'quote_duration_days' => 'integer',
            'quote_valid_until' => 'datetime',
            'quote_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'due_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'admin_seen_at' => 'datetime',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(AiSiteRequestMilestone::class, 'request_id')->orderBy('sort_order')->orderBy('id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(AiSiteRequestInstallment::class, 'request_id')->orderBy('sort_order')->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiSiteRequestMessage::class, 'request_id')->orderBy('id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, AiSiteRequestStatus::FINAL, true);
    }

    public function allPaid(): bool
    {
        $items = $this->relationLoaded('installments') ? $this->installments : $this->installments()->get();

        return $items->isNotEmpty() && $items->every(fn ($i) => $i->status === AiSiteRequestInstallment::PAID);
    }
}
