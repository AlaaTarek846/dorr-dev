<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Admin\Models\Admin;

/**
 * Someone reported a conversation. Carries who was reported and a copy of the last messages
 * (the evidence), and the admin's review.
 */
class ChatReport extends Model
{
    public const STATUSES = ['pending', 'reviewing', 'resolved', 'dismissed'];

    /** How many of the latest messages are copied into the report. */
    public const EVIDENCE_MESSAGES = 30;

    protected $fillable = [
        'conversation_id',
        'reporter_type',
        'reporter_id',
        'report_type_id',
        'details',
        'status',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ChatReportType::class, 'report_type_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function reportedUsers(): HasMany
    {
        return $this->hasMany(ChatReportUser::class, 'report_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatReportMessage::class, 'report_id')->orderBy('id');
    }
}
