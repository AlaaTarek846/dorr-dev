<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** FS-703: a follow-up a freelancer set for a client; the freelancer is told once when it is due (`notified_at`). */
class FreelanceCrmReminder extends Model
{
    protected $fillable = ['uuid', 'freelancer_id', 'user_id', 'title', 'due_at', 'done_at', 'notified_at'];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'done_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    public function freelancer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }
}
