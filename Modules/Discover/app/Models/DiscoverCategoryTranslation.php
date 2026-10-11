<?php

namespace Modules\Discover\Models;

use Illuminate\Database\Eloquent\Model;

class DiscoverCategoryTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['discover_category_id', 'locale', 'name'];
}
