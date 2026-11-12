<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Modules\User\Models\User;

/**
 * A client's order on a ready-made service (FL-L04). It keeps its own copy of what was bought; the work itself is its project's agreement,
 * so where the order stands is never stored — it is read from that agreement (waiting for the freelancer, active, completed, or ended).
 */
class FreelanceServiceOrder extends Model
{
    /** What the order shows, from its agreement's status. */
    public const STATUS_OF = [
        FreelanceAgreement::DRAFT => 'awaiting',
        FreelanceAgreement::PENDING => 'awaiting',
        FreelanceAgreement::ACTIVE => 'active',
        FreelanceAgreement::COMPLETED => 'completed',
        FreelanceAgreement::DECLINED => 'declined',
        FreelanceAgreement::EXPIRED => 'expired',
        FreelanceAgreement::CANCELLED => 'cancelled',
    ];

    /** The order's status => its agreement's. */
    public const FILTERS = [
        'awaiting' => [FreelanceAgreement::DRAFT, FreelanceAgreement::PENDING],
        'active' => [FreelanceAgreement::ACTIVE],
        'completed' => [FreelanceAgreement::COMPLETED],
        'ended' => [FreelanceAgreement::DECLINED, FreelanceAgreement::EXPIRED, FreelanceAgreement::CANCELLED],
    ];

    protected $fillable = [
        'uuid', 'service_id', 'client_id', 'freelancer_id', 'project_id', 'subscription_id', 'cycle', 'request_id', 'level', 'snapshot', 'price_minor', 'currency_code',
        'delivery_days', 'revisions', 'note',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceServiceOrder $o) => $o->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'price_minor' => 'integer', 'delivery_days' => 'integer', 'revisions' => 'integer', 'cycle' => 'integer'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FreelanceService::class, 'service_id')->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }

    /** FL-L05: the monthly subscription this order is one month of (`cycle`), or null. */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(FreelanceServiceSubscription::class, 'subscription_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    /** The project's agreement (an order's project has exactly one: it never takes other offers). */
    public function agreement(): HasOne
    {
        return $this->hasOne(FreelanceAgreement::class, 'project_id', 'project_id');
    }

    public function status(): string
    {
        return self::STATUS_OF[$this->agreement?->status] ?? 'awaiting';
    }

    /** @param  string  $status  one of FILTERS */
    public function scopeInStatus($query, string $status)
    {
        return $query->whereHas('agreement', fn ($q) => $q->whereIn('status', self::FILTERS[$status] ?? []));
    }
}
