<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Someone says a work shown on Dorr (a portfolio item or a ready-made service) is theirs, or was taken from them (FL-L13). The staff read
 * it and decide: upheld (the work is taken down and its owner told) or dismissed. The reporter is a signed-in client or anyone outside
 * (a rights holder who found it on the website).
 */
class FreelanceRightsClaim extends Model
{
    public const OPEN = 'open';

    public const UPHELD = 'upheld';

    public const DISMISSED = 'dismissed';

    public const STATUSES = [self::OPEN, self::UPHELD, self::DISMISSED];

    public const PORTFOLIO_ITEM = 'portfolio_item';

    public const SERVICE = 'service';

    public const SUBJECTS = [self::PORTFOLIO_ITEM, self::SERVICE];

    protected $fillable = [
        'uuid', 'subject_type', 'subject_id', 'subject_title', 'freelancer_id', 'reporter_type', 'reporter_id', 'reporter_name', 'reporter_contact',
        'details', 'status', 'decision_note', 'decided_by_admin_id', 'decided_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceRightsClaim $c) => $c->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }
}
