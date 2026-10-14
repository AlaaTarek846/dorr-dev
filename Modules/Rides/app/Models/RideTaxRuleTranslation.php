<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

class RideTaxRuleTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['ride_tax_rule_id', 'locale', 'label'];
}
