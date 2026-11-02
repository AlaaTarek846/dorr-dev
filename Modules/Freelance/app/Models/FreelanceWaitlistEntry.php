<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\User;

/**
 * A client in line for a ready-made service of a freelancer at full capacity (FL-L10). `waiting` until a place frees; then `offered` with
 * the place kept until `hold_until`; `ordered` once they order, `left` if they step out, `expired` if the hold passed unused.
 */
class FreelanceWaitlistEntry extends Model
{
    public const WAITING = 'waiting';

    public const OFFERED = 'offered';

    public const ORDERED = 'ordered';

    public const LEFT = 'left';

    public const EXPIRED = 'expired';

    /** Still in line (waiting or holding a place). */
    public const OPEN = [self::WAITING, self::OFFERED];

    protected $table = 'freelance_service_waitlist';

    protected $fillable = ['service_id', 'freelancer_id', 'client_id', 'status', 'offered_at', 'hold_until'];

    protected function casts(): array
    {
        return ['offered_at' => 'datetime', 'hold_until' => 'datetime'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FreelanceService::class, 'service_id')->withTrashed();
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /** Holding a place that has not run out. */
    public function holds(): bool
    {
        return $this->status === self::OFFERED && $this->hold_until !== null && $this->hold_until->isFuture();
    }
}
