<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Support\OwnerType;

/**
 * Not exposed directly outside Modules\Wallet\Services\PinService — nothing
 * else should read/write pin_hash or the attempt counters directly.
 */
class WalletPin extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'pin_hash',
        'failed_attempts',
        'locked_until',
        'changed_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
            'changed_at' => 'datetime',
        ];
    }

    /**
     * Deliberately not an Eloquent morphTo() — see OwnerType's docblock for
     * why owner_type isn't a global Relation::morphMap() alias.
     */
    public function owner(): ?Model
    {
        return OwnerType::modelClassFor($this->owner_type)::query()->find($this->owner_id);
    }
}
