<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\User\Models\User;

/**
 * One booked consultation (FL-L07): its time, the client's topic, the money held for it and how it ended. Its status only moves through
 * Consultations.
 */
class FreelanceConsultation extends Model
{
    /** Asked for and paid (held), waiting for the freelancer to accept: the time is not taken yet. */
    public const PENDING = 'pending';

    /** The freelancer said no, did not answer in time, or took that time for someone else: everything went back. */
    public const DECLINED = 'declined';

    /** Accepted by the freelancer (the time is theirs now) and not settled yet: before, during or just after the session. */
    public const BOOKED = 'booked';

    /** Paid out to the freelancer. */
    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /** The client said something went wrong; the staff decide. */
    public const DISPUTED = 'disputed';

    /** Given back to the client after a complaint. */
    public const REFUNDED = 'refunded';

    public const STATUSES = [self::PENDING, self::DECLINED, self::BOOKED, self::COMPLETED, self::CANCELLED, self::DISPUTED, self::REFUNDED];

    protected $fillable = [
        'uuid', 'freelancer_id', 'offer_id', 'client_id', 'country_id', 'request_id', 'minutes', 'starts_at', 'ends_at', 'topic', 'title', 'price_minor',
        'client_fee_minor', 'commission_minor', 'net_minor', 'currency_code', 'policy', 'status', 'cancelled_by', 'cancel_reason', 'late_cancel',
        'cancelled_at', 'complaint', 'complained_at', 'resolution', 'resolution_note', 'resolved_by', 'resolved_at', 'confirmed_at', 'settled_at',
        'refunded_at', 'reminded_at', 'ended_told_at', 'conversation_id', 'calendar_item_id', 'accepted_at', 'respond_by',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceConsultation $c) => $c->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'minutes' => 'integer', 'price_minor' => 'integer', 'client_fee_minor' => 'integer', 'commission_minor' => 'integer', 'net_minor' => 'integer',
            'policy' => 'array', 'late_cancel' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime',
            'complained_at' => 'datetime', 'resolved_at' => 'datetime', 'confirmed_at' => 'datetime', 'settled_at' => 'datetime',
            'refunded_at' => 'datetime', 'reminded_at' => 'datetime', 'ended_told_at' => 'datetime', 'accepted_at' => 'datetime', 'respond_by' => 'datetime',
        ];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /** The listing it was booked from (null once the freelancer removed it; the booking keeps the title). */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(FreelanceConsultationOffer::class, 'offer_id');
    }

    /** What the client pays: the price and the client fee. */
    public function totalMinor(): int
    {
        return $this->price_minor + $this->client_fee_minor;
    }

    /** Where it stands in time: upcoming · live · ended (awaiting the money) · or its closed status. */
    public function phase(): string
    {
        if ($this->status !== self::BOOKED) {
            return $this->status;
        }
        if ($this->starts_at->isFuture()) {
            return 'upcoming';
        }

        return $this->ends_at->isFuture() ? 'live' : 'ended';
    }

    /** When the money goes to the freelancer if the client says nothing. */
    public function releaseAt(): \Illuminate\Support\Carbon
    {
        return $this->ends_at->copy()->addHours(max(1, (int) ($this->policy['complaint_hours'] ?? 24)));
    }

    /** Until when the client cancels and gets everything back. */
    public function freeCancelUntil(): \Illuminate\Support\Carbon
    {
        return $this->starts_at->copy()->subHours(max(0, (int) ($this->policy['free_cancel_hours'] ?? 24)));
    }

    public function label(): string
    {
        return $this->title ?: __('freelance.consultation_label', ['minutes' => $this->minutes]);
    }
}
