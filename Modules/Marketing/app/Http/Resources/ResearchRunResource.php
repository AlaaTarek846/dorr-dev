<?php

namespace Modules\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketing\Models\MarketingResearchRun;
use Modules\Marketing\Models\MarketingSource;

/** @mixin MarketingResearchRun */
class ResearchRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'kind' => $this->kind,
            'status' => $this->status,
            'scope' => collect($this->query_scope ?? [])->except('locale')->all(),
            'summary' => $this->summary,
            'gaps' => $this->gaps ?? [],
            'quality_score' => $this->quality_score,
            'error_code' => $this->error_code,
            'sources_count' => $this->sources_count ?? ($this->relationLoaded('sources') ? $this->sources->count() : null),
            'findings_count' => $this->findings_count ?? ($this->relationLoaded('findings') ? $this->findings->count() : null),
            'sources' => $this->whenLoaded('sources', fn () => $this->sources->map(fn (MarketingSource $s) => [
                'ref' => $s->ref,
                'plan_ref' => 'S'.$s->id,
                'url' => $s->url,
                'title' => $s->title,
                'publisher' => $s->publisher,
                'published_at' => $s->published_at?->toDateString(),
                'retrieved_at' => $s->retrieved_at?->toIso8601String(),
                'excerpt' => $s->quote_excerpt === null ? null : mb_substr($s->quote_excerpt, 0, 400),
                'credibility' => $s->credibility_level,
            ])->all()),
            'findings' => $this->whenLoaded('findings', fn () => $this->findings->map(fn ($f) => [
                'id' => $f->id,
                'claim' => $f->claim,
                'sources' => $f->source_refs ?? [],
                'confidence' => $f->confidence,
                'is_assumption' => $f->is_assumption,
                'is_quantitative' => $f->is_quantitative,
            ])->all()),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
