<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\User\Models\User;

/** FS-103: one of a client's lists of freelancers. */
class FreelanceTalentList extends Model
{
    protected $fillable = ['uuid', 'user_id', 'name', 'freelance_specialty_id'];

    protected static function booted(): void
    {
        static::creating(function (FreelanceTalentList $l): void {
            $l->uuid ??= (string) Str::uuid();
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(FreelanceSpecialty::class, 'freelance_specialty_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FreelanceTalentListItem::class, 'list_id');
    }
}
