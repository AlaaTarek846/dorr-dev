<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Admin\Models\Admin;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\CaseKind;
use Modules\Rides\Enums\CaseStatus;
use Modules\User\Models\User;

/**
 * A complaint, a financial dispute or an unpaid-ride report. Never deleted (D-238): the record outlives the
 * ride's closing and the reporter's account being closed or blocked.
 */
class RideCase extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('ride_cases are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => CaseKind::class,
            'status' => CaseStatus::class,
            'evidence' => 'array',
            'decision' => 'array',
            'disputed_minor' => 'integer',
            'decided_at' => 'datetime',
            'appealed_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RideCaseMessage::class, 'case_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(RideCaseFile::class, 'case_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RideCaseEvent::class, 'case_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'decided_by_admin_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    /** The user or driver who opened it. */
    public function reporter(): ?Model
    {
        return $this->reporter_type === 'driver' ? Driver::query()->find($this->reporter_id) : User::query()->find($this->reporter_id);
    }

    public function isOwnedBy(Model $party): bool
    {
        return $this->reporter_type === ($party instanceof Driver ? 'driver' : 'user') && (int) $this->reporter_id === (int) $party->getKey();
    }
}
