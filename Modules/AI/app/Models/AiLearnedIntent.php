<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiLearnedIntent extends Model
{
    public const MODE_PHRASE = 'phrase';

    public const MODE_EXACT = 'exact';

    public const SOURCE_MODEL = 'model';

    public const SOURCE_ADMIN = 'admin';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'phrase', 'match_mode', 'intent', 'file_format', 'language', 'confidence', 'confirmations',
        'conflicts', 'hits', 'last_hit_at', 'is_active', 'source', 'learned_with_model',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'confidence' => 'float',
            'last_hit_at' => 'datetime',
        ];
    }
}
