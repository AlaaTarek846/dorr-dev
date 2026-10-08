<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Model;

class SportsCompetitionTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['sports_competition_id', 'locale', 'name'];
}
