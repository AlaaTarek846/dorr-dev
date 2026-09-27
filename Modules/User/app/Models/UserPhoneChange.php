<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A pending "I want to change my phone to this number" request — one per user, replaced (not
 * accumulated) on a fresh request. Deleted as soon as it is confirmed or abandoned.
 */
class UserPhoneChange extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'new_phone', 'code', 'attempts', 'expires_at'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
