<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiFileResource extends JsonResource
{
    /**
     * Doc S16 ("do NOT expose physical server paths / internal
     * filesystem paths / provider secret information") - `file_path` is
     * deliberately left out. `file_name`/`file_size` keep their existing
     * names rather than being renamed to the doc's example `name`/`size`
     * (doc S1: "no unnecessary breaking changes") - the admin dashboard
     * (resources/js/.../ai-files/index.vue) already reads
     * `file.file_name`/`file.file_size` from this exact resource.
     * `status` is an alias of `processing_status` for response-shape
     * compatibility with the doc's example response (this module reuses
     * one lifecycle column rather than storing the same state twice -
     * see the Phase 1 report).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'type' => $this->owner_type,
                'id' => $this->owner_id,
                'name' => $this->owner->name ?? null,
            ] : null),
            'source_type' => $this->source_type,
            'conversation_id' => $this->conversation_id,
            'message_id' => $this->message_id,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'file_size' => $this->file_size,
            'checksum' => $this->checksum,
            'status' => $this->processing_status,
            'processing_status' => $this->processing_status,
            'processing_error' => $this->processing_error,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            // Phase 8 (doc S49): chunking/indexing summary for the admin
            // File Engine dashboard - only populated when the ->chunks
            // relation was eager-loaded (AiFileService::show(), the
            // single-file view - never the paginated list, see its own
            // docblock).
            'chunking' => $this->whenLoaded('chunks', function () {
                $chunks = $this->chunks;
                $active = $chunks->where('is_active', true);

                return [
                    'chunk_count' => $active->count(),
                    'total_chunk_count' => $chunks->count(),
                    'content_version' => $active->max('content_version'),
                    'failed_chunk_count' => $active->where('status', 'failed')->count(),
                    'indexed_chunk_count' => $active->where('status', 'indexed')->count(),
                    'indexing_status' => $active->isEmpty() ? 'not_chunked' : (
                        $active->every(fn ($c) => $c->status === 'indexed') ? 'indexed' : (
                            $active->contains(fn ($c) => $c->status === 'failed') ? 'partially_failed' : 'indexing'
                        )
                    ),
                    'last_indexed_at' => $active->max('indexed_at'),
                    // Phase 9 (doc S33): embedding visibility - distinct
                    // from indexing_status above, since a chunk can be
                    // fully indexed (keyword-searchable) while never
                    // embedded at all (the common case today,
                    // `indexing.auto_embed` defaults false).
                    'embedding' => [
                        'embedded_chunk_count' => $active->where('embedding_status', 'embedded')->count(),
                        'failed_embedding_count' => $active->where('embedding_status', 'failed')->count(),
                        'stale_embedding_count' => $active->where('embedding_status', 'stale')->count(),
                        'embedding_model' => $active->firstWhere('embedding_status', 'embedded')?->embedding_model,
                        'retrieval_backend' => $active->where('embedding_status', 'embedded')->isNotEmpty() ? 'hybrid' : 'keyword',
                    ],
                ];
            }),
            // Phase 10 (doc S41, optional admin visibility): how many
            // conversations currently have this file explicitly
            // attached (status=attached only - a detached relationship
            // row does not count) - only populated when the
            // conversationFiles relation was eager-loaded
            // (AiFileService::show(), same pattern as 'chunking' above).
            'conversations' => $this->whenLoaded('conversationFiles', function () {
                $rows = $this->conversationFiles;

                return [
                    'attached_count' => $rows->where('status', 'attached')->count(),
                    'detached_count' => $rows->where('status', 'detached')->count(),
                ];
            }),
        ];
    }
}
