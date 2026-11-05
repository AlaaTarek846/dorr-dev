<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\User\Models\User;

/**
 * A freelancer's special offer to a client they already worked with (FL-L09). `custom` is new work at the freelancer's price, `service` one
 * of their ready-made services at a lower price, `consultation` one of their consultations at a discount. What the client sees is kept as
 * it was when sent; accepting starts it (the project, the order or the booking is linked here).
 */
class FreelanceSpecialOffer extends Model
{
    public const CUSTOM = 'custom';

    public const SERVICE = 'service';

    public const CONSULTATION = 'consultation';

    public const KINDS = [self::CUSTOM, self::SERVICE, self::CONSULTATION];

    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const WITHDRAWN = 'withdrawn';

    public const EXPIRED = 'expired';

    public const STATUSES = [self::SENT, self::ACCEPTED, self::DECLINED, self::WITHDRAWN, self::EXPIRED];

    /** The list tabs: open = waiting for the client; past = everything answered or ended. */
    public const TABS = ['open' => [self::SENT], 'accepted' => [self::ACCEPTED], 'past' => [self::DECLINED, self::WITHDRAWN, self::EXPIRED]];

    protected $fillable = [
        'uuid', 'freelancer_id', 'client_id', 'country_id', 'kind', 'freelance_specialty_id', 'service_id', 'service_level', 'consultation_offer_id',
        'title', 'message', 'description', 'price_minor', 'list_price_minor', 'discount_bp', 'delivery_days', 'revisions', 'currency_code', 'snapshot',
        'status', 'expires_at', 'viewed_at', 'reminded_at', 'answered_at', 'decline_reason', 'request_id', 'project_id', 'service_order_id', 'consultation_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceSpecialOffer $o) => $o->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array', 'price_minor' => 'integer', 'list_price_minor' => 'integer', 'discount_bp' => 'integer',
            'delivery_days' => 'integer', 'revisions' => 'integer', 'expires_at' => 'datetime', 'viewed_at' => 'datetime', 'reminded_at' => 'datetime', 'answered_at' => 'datetime',
        ];
    }

    /** Still waiting for the client and not past its time (the hourly sweep marks the rest expired). */
    public function open(): bool
    {
        return $this->status === self::SENT && $this->expires_at->isFuture();
    }

    /** The status the apps show: a sent offer past its time is expired even before the sweep. */
    public function shownStatus(): string
    {
        return $this->status === self::SENT && ! $this->expires_at->isFuture() ? self::EXPIRED : $this->status;
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FreelanceService::class, 'service_id')->withTrashed();
    }

    public function consultationOffer(): BelongsTo
    {
        return $this->belongsTo(FreelanceConsultationOffer::class, 'consultation_offer_id');
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(FreelanceSpecialty::class, 'freelance_specialty_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(FreelanceServiceOrder::class, 'service_order_id');
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(FreelanceConsultation::class, 'consultation_id');
    }
}
