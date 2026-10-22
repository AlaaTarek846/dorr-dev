<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;

class FreelanceSpecialtyTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['freelance_specialty_id', 'locale', 'name', 'description'];
}
