<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;

class ChatGroup extends Model implements HasMedia
{
    use HasMediaTrait;

    protected $fillable = [
        'conversation_id',
        'name',
        'description',
        'invite_token',
        'only_admins_send',
        'only_admins_edit_info',
        'only_admins_add_members',
        'approve_joins',
    ];

    protected function casts(): array
    {
        return [
            'only_admins_send' => 'boolean',
            'only_admins_edit_info' => 'boolean',
            'only_admins_add_members' => 'boolean',
            'approve_joins' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function avatarUrl(): ?string
    {
        return $this->getSingleMediaUrl('avatar') ?: null;
    }
}
