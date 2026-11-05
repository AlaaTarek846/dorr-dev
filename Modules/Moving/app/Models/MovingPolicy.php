<?php

namespace Modules\Moving\Models;

use Illuminate\Database\Eloquent\Model;

/** The moving values one country set (or the default row, country null). See config `moving.policy_keys`. */
class MovingPolicy extends Model
{
    protected $table = 'moving_policies';

    protected $fillable = ['country_id', 'values', 'version', 'updated_by_admin_id'];

    protected function casts(): array
    {
        return ['values' => 'array', 'version' => 'integer'];
    }
}
