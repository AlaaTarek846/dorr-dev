<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Model;

class SportsTeamTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['sports_team_id', 'locale', 'name'];
}
