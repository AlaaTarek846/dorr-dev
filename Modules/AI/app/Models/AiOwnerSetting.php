<?php

namespace Modules\AI\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's own DORR AI switches (today: memory on/off, spec 143). No row = the defaults.
 */
class AiOwnerSetting extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'memory_enabled',
    ];

    protected function casts(): array
    {
        return [
            'memory_enabled' => 'boolean',
        ];
    }

    public static function for(Authenticatable $owner): self
    {
        return self::query()->firstOrNew(
            ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getAuthIdentifier()],
            ['memory_enabled' => true],
        );
    }
}
