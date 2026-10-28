<?php

namespace Modules\Freelance\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;

/** One message of a freelance room: text, a file or image (with an optional caption), or a system line. */
class FreelanceMessage extends Model implements HasMedia
{
    use HasMediaTrait;

    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'sender_type', 'sender_id', 'kind', 'body', 'client_id', 'read_at', 'deleted_at', 'created_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'deleted_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(FreelanceConversation::class, 'conversation_id');
    }
}
