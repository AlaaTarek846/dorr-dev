<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Chat\Contracts\ProtectedMediaOwner;
use Modules\Chat\Support\GroupScope;
use Spatie\MediaLibrary\HasMedia;

/** Homework in an educational group (GR-014): individual or team hand-ins, a grade for each student. */
class ChatGroupAssignment extends Model implements HasMedia, ProtectedMediaOwner
{
    use HasMediaTrait;

    public const MATERIALS = 'materials';

    protected $fillable = ['uuid', 'conversation_id', 'space_id', 'title', 'instructions', 'due_at', 'mode', 'max_score', 'created_by_participant_id'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'max_score' => 'integer'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ChatGroupSubmission::class, 'assignment_id');
    }

    public function protectedMediaAllows(Model $viewer): bool
    {
        return GroupScope::canSee($viewer, $this->conversation_id, $this->space_id, 'assignments.manage');
    }
}
