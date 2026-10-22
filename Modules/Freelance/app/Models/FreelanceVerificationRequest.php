<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One verification request a freelancer sent, and the admin's decision on it. Kept forever: a second try is a new row. */
class FreelanceVerificationRequest extends Model
{
    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CHANGES_REQUESTED = 'changes_requested';

    protected $fillable = ['uuid', 'freelancer_id', 'status', 'submitted_at', 'decided_at', 'decided_by_admin_id', 'reason'];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceVerificationRequest $r) => $r->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FreelanceVerificationDocument::class, 'request_id');
    }
}
