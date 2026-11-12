<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A spending cap for a period (U08): `ads` in money (what the business spends on the platforms, as imported) and
 * `ai` in marketing AI calls — two separate caps (spec 09). Set by the owner, or proposed by a manager and approved
 * by the owner; changing the cap needs the owner again (AT-13).
 */
class MarketingBudget extends Model
{
    public const KINDS = ['ads', 'ai'];

    public const PENDING = 'PENDING';

    public const APPROVED = 'APPROVED';

    public const CLOSED = 'CLOSED';

    protected $fillable = [
        'uuid', 'project_id', 'kind', 'period_start', 'period_end', 'currency', 'cap_minor', 'cap_calls', 'alert_pct',
        'approval_status', 'last_alert_level', 'note', 'lock_version', 'created_by_type', 'created_by_id',
        'approved_by_type', 'approved_by_id', 'approved_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $budget) {
            $budget->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'cap_minor' => 'integer',
            'cap_calls' => 'integer',
            'alert_pct' => 'integer',
            'lock_version' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MarketingProject::class, 'project_id');
    }

    public function cap(): int
    {
        return (int) ($this->kind === 'ai' ? $this->cap_calls : $this->cap_minor);
    }
}
