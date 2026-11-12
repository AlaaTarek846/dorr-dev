<?php

namespace Modules\AI\Models;

use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasIsActiveAlias;

/**
 * One standing instruction of a person's AI project: added to the system message of every conversation in the
 * project, the highest priority first (AiUserProjectService::systemMessage), and shown in the person's project.
 * Has the countries list (status, trash) but no translation table: the instruction is a prompt sent to the AI as it is.
 *
 * Switched off or in the trash = never sent and not shown. `$instruction->is_active` reads `status`
 * (HasIsActiveAlias).
 */
class AiProjectInstruction extends Model
{
    use HasIsActiveAlias, SearchFilterTrait, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'instruction',
        'priority',
        'status',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'project_id');
    }
}
