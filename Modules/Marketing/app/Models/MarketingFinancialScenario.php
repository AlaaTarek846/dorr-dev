<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingFinancialScenario extends Model
{
    public const SCENARIOS = ['conservative', 'base', 'optimistic'];

    protected $fillable = ['plan_version_id', 'scenario', 'inputs', 'outputs', 'calculation_version'];

    protected function casts(): array
    {
        return ['inputs' => 'array', 'outputs' => 'array'];
    }
}
