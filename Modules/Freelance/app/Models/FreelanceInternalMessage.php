<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * FS-150d: one message in a team's internal thread. Written once; its sender may take it back for a few minutes (`deleted_at`). The text of a
 * deleted message is kept only while the thread's project has a dispute being decided - for the staff, never for the team.
 *
 * @property int $id
 * @property int $thread_id
 * @property ?int $sender_freelancer_id
 * @property string $kind
 * @property ?string $body
 * @property ?string $client_msg_id
 * @property ?Carbon $created_at
 * @property ?Carbon $deleted_at
 */
class FreelanceInternalMessage extends Model
{
    public $timestamps = false;

    protected $table = 'freelance_internal_messages';

    protected $fillable = ['thread_id', 'sender_freelancer_id', 'kind', 'body', 'client_msg_id', 'created_at', 'deleted_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(FreelanceInternalThread::class, 'thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'sender_freelancer_id');
    }
}
