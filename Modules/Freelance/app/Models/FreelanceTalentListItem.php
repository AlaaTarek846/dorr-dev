<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FS-103: a freelancer in a client's list, with the client's note and (only on his request) a reminder to hire again. */
class FreelanceTalentListItem extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['list_id', 'freelancer_id', 'note', 'remind_at', 'reminded_at'];

    protected function casts(): array
    {
        return ['remind_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(FreelanceTalentList::class, 'list_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'freelancer_id');
    }
}
