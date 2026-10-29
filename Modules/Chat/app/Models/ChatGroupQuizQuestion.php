<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** One question of a test: choice / true_false (marked automatically) or essay (by hand). */
class ChatGroupQuizQuestion extends Model
{
    public const TYPES = ['choice', 'true_false', 'essay'];

    protected $fillable = ['quiz_id', 'type', 'text', 'options', 'correct', 'points', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array', 'correct' => 'array', 'points' => 'float', 'sort_order' => 'integer'];
    }
}
