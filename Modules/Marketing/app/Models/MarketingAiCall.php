<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

/** One marketing AI call for a project — what the AI budget counts (spec 09: AI cap separate from ad spend). */
class MarketingAiCall extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'source', 'input_chars', 'output_chars', 'actor_type', 'actor_id'];
}
