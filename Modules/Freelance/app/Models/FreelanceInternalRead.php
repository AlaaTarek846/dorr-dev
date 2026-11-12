<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FS-150d: how far one person read one internal thread (the newest message id he saw). Unread = the others' messages after it.
 *
 * @property int $thread_id
 * @property int $freelancer_id
 * @property int $last_read_message_id
 */
class FreelanceInternalRead extends Model
{
    public const CREATED_AT = null;

    protected $table = 'freelance_internal_reads';

    protected $fillable = ['thread_id', 'freelancer_id', 'last_read_message_id'];

    protected function casts(): array
    {
        return ['last_read_message_id' => 'integer'];
    }
}
