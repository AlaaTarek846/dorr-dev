<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingSource extends Model
{
    protected $fillable = ['research_run_id', 'ref', 'url', 'title', 'publisher', 'published_at', 'retrieved_at', 'quote_excerpt', 'credibility_level'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'retrieved_at' => 'datetime'];
    }
}
