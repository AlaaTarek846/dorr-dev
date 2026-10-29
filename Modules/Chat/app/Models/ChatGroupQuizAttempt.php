<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's one go at a test: answers, marks per question, and whether written answers wait for marking. */
class ChatGroupQuizAttempt extends Model
{
    protected $fillable = ['quiz_id', 'participant_id', 'answers', 'marks', 'started_at', 'submitted_at', 'needs_marking'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'marks' => 'array', 'started_at' => 'datetime', 'submitted_at' => 'datetime', 'needs_marking' => 'boolean'];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(ChatGroupQuiz::class, 'quiz_id');
    }

    public function total(): float
    {
        return (float) array_sum(array_map('floatval', (array) ($this->marks ?? [])));
    }
}
