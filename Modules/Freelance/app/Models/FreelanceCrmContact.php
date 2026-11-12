<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\User\Models\User;

/** FS-703: one client in a freelancer's private CRM — the label and the star the freelancer gave (the client never sees them). */
class FreelanceCrmContact extends Model
{
    protected $fillable = ['uuid', 'freelancer_id', 'user_id', 'label', 'starred'];

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['starred' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(FreelanceCrmNote::class, 'user_id', 'user_id')->whereColumn('freelance_crm_notes.freelancer_id', 'freelance_crm_contacts.freelancer_id');
    }
}
