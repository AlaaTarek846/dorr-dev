<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class AiConversation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'title',
        'provider_key',
    ];

    /**
     * ai_conversation_attachments.conversation_id uses a DB-level
     * cascadeOnDelete(), so deleting a conversation removes the
     * attachment *rows* automatically - but a DB cascade never fires
     * Eloquent model events, and it never touches the actual file sitting
     * on the "public" disk. Left alone, an "erase my data" call (v2.0
     * requirements doc S17.3) would delete every row while silently
     * leaving every uploaded file behind on disk - which also compounds
     * the already-known public-attachment-URL gap: a file the owner just
     * asked to erase could still be sitting at its old, guessable public
     * URL. This walks the attachments directly (not the cascaded rows,
     * which may already be gone by the time this runs depending on
     * timing) and deletes each physical file before the row cascade
     * proceeds.
     */
    protected static function booted(): void
    {
        static::deleting(function (AiConversation $conversation) {
            AiConversationAttachment::query()
                ->where('conversation_id', $conversation->id)
                ->get()
                ->each(function (AiConversationAttachment $attachment) {
                    if ($attachment->file_path) {
                        Storage::disk('public')->delete($attachment->file_path);
                    }
                });
        });
    }

    /**
     * The User or Provider this conversation belongs to.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function messages(): HasMany
    {
        // sequence_number is now assigned atomically per conversation
        // (AiChatService::createSequencedMessage()) and is the more
        // reliable ordering under concurrent inserts than created_at,
        // whose precision two near-simultaneous writes can tie on; id is
        // a final tiebreaker for any pre-existing row still null.
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('sequence_number')->orderBy('id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(AiMessage::class, 'conversation_id')->latestOfMany();
    }

    public function contexts(): HasMany
    {
        return $this->hasMany(AiConversationContext::class, 'conversation_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AiConversationAttachment::class, 'conversation_id');
    }

    public function instructions(): HasMany
    {
        return $this->hasMany(AiConversationInstruction::class, 'conversation_id');
    }

    /**
     * Phase 10: the explicit many-to-many conversation<->file
     * relationship rows (any status - see AiConversationFile).
     * AiConversationFileScope is the one place that filters these down
     * to "currently attached and searchable".
     */
    public function conversationFiles(): HasMany
    {
        return $this->hasMany(AiConversationFile::class, 'conversation_id');
    }
}
