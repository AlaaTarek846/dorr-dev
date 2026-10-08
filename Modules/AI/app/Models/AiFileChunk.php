<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AiFileChunk extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_INDEXING = 'indexing';

    public const STATUS_INDEXED = 'indexed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_STALE = 'stale';

    public const STATUS_DELETED = 'deleted';

    public const EMBEDDING_PENDING = 'pending';

    public const EMBEDDING_PROCESSING = 'processing';

    public const EMBEDDING_EMBEDDED = 'embedded';

    public const EMBEDDING_FAILED = 'failed';

    public const EMBEDDING_STALE = 'stale';

    public const EMBEDDING_DELETED = 'deleted';

    protected $fillable = [
        'file_id',
        'content_version',
        'chunk_index',
        'chunk_key',
        'content_ref',
        'content_type',
        'token_count',
        'token_count_is_estimated',
        'character_count',
        'checksum',
        'metadata',
        'status',
        'is_active',
        'indexed_at',
        'embedding_status',
        'embedding_model',
        'embedding_provider',
        'embedded_at',
    ];

    protected function casts(): array
    {
        return [
            'content_version' => 'integer',
            'chunk_index' => 'integer',
            'token_count' => 'integer',
            'token_count_is_estimated' => 'boolean',
            'character_count' => 'integer',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'indexed_at' => 'datetime',
            'embedded_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(AiFile::class);
    }

    /**
     * Reads the chunk's actual text content off disk (doc S28/content-
     * ref-on-disk pattern reused from Phase 1 `ai_files.extracted_content_ref`
     * and the admin Knowledge Base's `ai_knowledge_chunks.content_ref`).
     * Never duplicates large content directly into the DB row.
     */
    public function readContent(): ?string
    {
        return $this->readPayload()['content'] ?? null;
    }

    /**
     * Phase 9: the embedding vector lives in the SAME content_ref JSON
     * file as the text (mirroring ai_knowledge_chunks' own
     * content+embedding shape) - never a second file, never a column
     * (a 1536-float vector has no business being a relational column).
     * Returns null when no embedding was ever computed, OR when the
     * stored embedding_model no longer matches the currently configured
     * one (doc S10: "embedding must become stale when... embedding
     * model changes" - checked here at read time rather than requiring
     * a background reconciliation sweep, a deliberate simplification
     * disclosed in this phase's report).
     *
     * @return ?list<float>
     */
    public function readEmbedding(): ?array
    {
        if ($this->embedding_status !== self::EMBEDDING_EMBEDDED) {
            return null;
        }

        $configuredModel = (string) config('ai.knowledge.embedding_model', 'text-embedding-3-small');

        if ($this->embedding_model !== null && $this->embedding_model !== $configuredModel) {
            return null;
        }

        $vector = $this->readPayload()['embedding'] ?? null;

        return is_array($vector) ? $vector : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readPayload(): array
    {
        $disk = (string) config('ai.chunking.storage_disk', config('ai.files.default_disk', 'public'));

        if (! $this->content_ref || ! Storage::disk($disk)->exists($this->content_ref)) {
            return [];
        }

        $decoded = json_decode(Storage::disk($disk)->get($this->content_ref), true);

        return is_array($decoded) ? $decoded : [];
    }
}
