<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiProject extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_DELETED = 'deleted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'name',
        'description',
        'settings',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * The User or Provider this project belongs to.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(AiWorkspace::class, 'project_id');
    }

    public function instructions(): HasMany
    {
        return $this->hasMany(AiProjectInstruction::class, 'project_id');
    }

    public function contextItems(): HasMany
    {
        return $this->hasMany(AiProjectContext::class, 'project_id');
    }

    public function projectConversations(): HasMany
    {
        return $this->hasMany(AiProjectConversation::class, 'project_id');
    }

    public function projectFiles(): HasMany
    {
        return $this->hasMany(AiProjectFile::class, 'project_id');
    }

    public function projectKnowledge(): HasMany
    {
        return $this->hasMany(AiProjectKnowledge::class, 'project_id');
    }
}
