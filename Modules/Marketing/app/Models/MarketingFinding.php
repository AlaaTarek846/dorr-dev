<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

/** One claim of a run. A claim with no valid source is an assumption; a number with no source is never kept. */
class MarketingFinding extends Model
{
    protected $fillable = ['research_run_id', 'claim', 'source_refs', 'confidence', 'is_assumption', 'is_quantitative'];

    protected function casts(): array
    {
        return ['source_refs' => 'array', 'is_assumption' => 'boolean', 'is_quantitative' => 'boolean'];
    }
}
