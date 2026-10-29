<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Chat\Contracts\ProtectedMediaOwner;
use Modules\Chat\Support\GroupPermissions;
use Modules\Chat\Support\GroupScope;
use Spatie\MediaLibrary\HasMedia;

/** A certificate given to a student (GR-028): its PDF opens for that student and the teachers only. */
class ChatGroupCertificate extends Model implements HasMedia, ProtectedMediaOwner
{
    use HasMediaTrait;

    public const PDF = 'pdf';

    protected $fillable = ['uuid', 'conversation_id', 'participant_id', 'title', 'reason', 'issued_by_participant_id'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function protectedMediaAllows(Model $viewer): bool
    {
        $member = GroupScope::member($viewer, $this->conversation_id);
        $conversation = ChatConversation::query()->find($this->conversation_id);

        return $member !== null && $conversation !== null
            && ($member->id === $this->participant_id || in_array('lessons.manage', GroupPermissions::ofParticipant($member, $conversation), true));
    }
}
