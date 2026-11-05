<?php

namespace Modules\Provider\Models;

use Illuminate\Database\Eloquent\Model;

/** One permission key held by a membership; the keys are listed in Modules/Provider/config/config.php. */
class MembershipPermission extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'provider_membership_permissions';

    protected $fillable = ['membership_id', 'permission'];
}
