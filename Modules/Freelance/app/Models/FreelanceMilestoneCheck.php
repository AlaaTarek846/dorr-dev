<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** One line of what the client will check before approving a part (FL-604). */
class FreelanceMilestoneCheck extends Model
{
    protected $fillable = ['uuid', 'milestone_id', 'position', 'text', 'checked_at'];

    protected static function booted(): void
    {
        static::creating(function (FreelanceMilestoneCheck $c): void {
            $c->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'position' => 'integer'];
    }
}
