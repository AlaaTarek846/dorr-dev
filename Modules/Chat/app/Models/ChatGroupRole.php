<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role in an educational or work group (docs/groups_tasks.md GR-004): a template or one the
 * group made, and the permissions it gives (GroupPermissions keys).
 */
class ChatGroupRole extends Model
{
    protected $fillable = ['conversation_id', 'template', 'name', 'permissions', 'is_default', 'sort_order'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_default' => 'boolean', 'sort_order' => 'integer'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(ChatGroupMemberRole::class, 'role_id');
    }

    /** Its own name, else the template's in the reader's language. */
    public function displayName(): string
    {
        return filled($this->name) ? (string) $this->name : (string) __('chat.group_roles.'.$this->template);
    }
}
