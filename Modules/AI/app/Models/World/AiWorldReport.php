<?php

namespace Modules\AI\Models\World;

use Illuminate\Database\Eloquent\Model;

/** A complaint, deletion request or impersonation report, reviewed by a person in the ops console. */
class AiWorldReport extends Model
{
    public const TYPES = ['complaint', 'deletion', 'impersonation'];

    protected $table = 'ai_world_reports';

    protected $fillable = ['project_id', 'reporter_type', 'reporter_id', 'type', 'status', 'notes', 'resolved_by'];
}
