<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Chat\Contracts\ProtectedMediaOwner;
use Modules\Chat\Support\GroupScope;
use Spatie\MediaLibrary\HasMedia;

/** A lesson and its materials in an educational group (GR-013) — for the group or one term / subgroup. */
class ChatGroupLesson extends Model implements HasMedia, ProtectedMediaOwner
{
    use HasMediaTrait;

    public const MATERIALS = 'materials';

    protected $fillable = ['uuid', 'conversation_id', 'space_id', 'title', 'body', 'created_by_participant_id'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function protectedMediaAllows(Model $viewer): bool
    {
        return GroupScope::canSee($viewer, $this->conversation_id, $this->space_id, 'lessons.manage');
    }
}
