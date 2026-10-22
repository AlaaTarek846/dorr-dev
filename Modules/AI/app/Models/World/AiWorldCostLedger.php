<?php

namespace Modules\AI\Models\World;

use Illuminate\Database\Eloquent\Model;

/** Append-only record of every charge and refund of a project. */
class AiWorldCostLedger extends Model
{
    public const CHARGE = 'charge';

    public const REFUND = 'refund';

    protected $table = 'ai_world_cost_ledger';

    protected $fillable = ['project_id', 'scene_id', 'type', 'amount_minor', 'currency', 'operation_id', 'note'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
