<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** FS-703: a private note a freelancer wrote about a client. */
class FreelanceCrmNote extends Model
{
    protected $fillable = ['uuid', 'freelancer_id', 'user_id', 'body'];

    protected static function booted(): void
    {
        static::creating(fn (self $n) => $n->uuid ??= (string) Str::uuid());
    }
}
