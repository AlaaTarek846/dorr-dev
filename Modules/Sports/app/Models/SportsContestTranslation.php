<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Model;

class SportsContestTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['sports_contest_id', 'locale', 'name', 'terms'];
}
