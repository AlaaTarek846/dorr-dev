<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** My Moments settings (spec 168): on / off, whose occasions, which ones, how much animation. */
class ChatMomentPreference extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'enabled', 'country_id', 'effects', 'picked', 'muted'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'picked' => 'array', 'muted' => 'array'];
    }
}
