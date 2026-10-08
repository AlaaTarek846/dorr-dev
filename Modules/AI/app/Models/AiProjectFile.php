<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProjectFile extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'file_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'project_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(AiFile::class, 'file_id');
    }
}
