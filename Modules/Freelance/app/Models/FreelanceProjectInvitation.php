<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A client's invitation of one freelancer to a private project (FL-205).
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int $freelancer_id
 * @property int $client_id
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $invited_at
 * @property ?\Illuminate\Support\Carbon $responded_at
 */
class FreelanceProjectInvitation extends Model
{
    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const WITHDRAWN = 'withdrawn';

    public const EXPIRED = 'expired';

    /** Still counts: the freelancer may see the project and offer on it. */
    public const LIVE = [self::SENT, self::ACCEPTED];

    protected $fillable = ['uuid', 'project_id', 'freelancer_id', 'client_id', 'status', 'invited_at', 'responded_at'];

    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $i): void {
            $i->uuid ??= (string) Str::uuid();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'freelancer_id');
    }
}
