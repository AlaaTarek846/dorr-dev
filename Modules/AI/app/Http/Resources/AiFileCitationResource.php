<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 11 (doc S14/S37): the citations/sources UI's data contract.
 * Exposes only real, already-computed fields - file id/name, the
 * excerpt, the retrieval score/method, and whichever type-specific
 * location fields AiRetrievalEngine::sourceReference() actually put on
 * this citation (page/section, sheet/row_start/row_end, slide,
 * timestamp_start/timestamp_end) - never a pre-rendered "Page 3" label
 * (that is a frontend i18n concern, see docs/file-engine.md Phase 11
 * section) and never chunk_id/embedding_id/owner ids (doc S37).
 */
class AiFileCitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reference = (array) ($this->source_reference ?? []);

        // file_id/file_name are already duplicated onto every citation
        // row by AiContextBuilder::build() (doc S21's sourceReference),
        // but $this->file (the relation, when loaded) is the
        // authoritative, current file name - a file can be renamed
        // after the citation was stored, so prefer the live relation
        // and only fall back to the frozen reference snapshot.
        return [
            'id' => $this->id,
            'number' => $this->position,
            'file_id' => $this->file_id,
            'file_name' => $this->whenLoaded('file', fn () => $this->file?->file_name, $reference['file_name'] ?? null),
            'excerpt' => $this->excerpt,
            'score' => $this->relevance_score,
            'retrieval_method' => $this->retrieval_method,
            // Only ever the subset of these keys the chunk's own
            // content type produced - a document-text citation carries
            // page/section, a spreadsheet one carries
            // sheet/row_start/row_end, and so on (see
            // AiRetrievalEngine::sourceReference()). Never invented.
            'location' => array_filter([
                'page' => $reference['page'] ?? null,
                'section' => $reference['section'] ?? null,
                'sheet' => $reference['sheet'] ?? null,
                'row_start' => $reference['row_start'] ?? null,
                'row_end' => $reference['row_end'] ?? null,
                'slide' => $reference['slide'] ?? null,
                'timestamp_start' => $reference['timestamp_start'] ?? null,
                'timestamp_end' => $reference['timestamp_end'] ?? null,
            ], fn ($v) => $v !== null),
        ];
    }
}
