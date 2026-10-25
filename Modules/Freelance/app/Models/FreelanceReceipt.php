<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * A receipt of one money movement of a part, for one side (FL-511). Written only, with the movement; never changed or deleted.
 *
 * @property int $id
 * @property string $uuid
 * @property string $number
 * @property string $party
 * @property string $kind
 * @property ?string $reason
 * @property string $currency_code
 * @property int $amount_minor
 * @property int $fee_minor
 * @property int $total_minor
 * @property int $refunded_minor
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class FreelanceReceipt extends Model
{
    public const UPDATED_AT = null;

    public const KINDS = ['funded', 'paid', 'refunded', 'settled', 'earned'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'amount_minor' => 'integer', 'fee_minor' => 'integer', 'total_minor' => 'integer', 'refunded_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $r): void {
            $r->uuid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new LogicException('A receipt is never changed.'));
        static::deleting(fn () => throw new LogicException('A receipt is never deleted.'));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(FreelanceMilestone::class, 'milestone_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(\Modules\User\Models\User::class, 'client_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'freelancer_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Country::class, 'country_id');
    }
}
