<?php

namespace Modules\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketing\Models\MarketingFinancialScenario;
use Modules\Marketing\Models\MarketingPlanVersion;

/** @mixin MarketingPlanVersion */
class PlanVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version_no,
            'origin' => $this->origin,
            'content' => $this->structured_json,
            'content_hash' => $this->content_hash,
            'critic' => $this->critic ?? [],
            // Always a JSON object (`{}` when none yet): the apps read it as a map, and an empty PHP array would go out as `[]`.
            'scenarios' => (object) $this->scenarios()->get()->mapWithKeys(fn (MarketingFinancialScenario $s) => [$s->scenario => [
                'inputs' => $s->inputs,
                'outputs' => $s->outputs,
                'calculation_version' => $s->calculation_version,
            ]])->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
