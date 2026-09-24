<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiKnowledgeSource extends Model
{
    public const CLASSIFICATION_PUBLIC = 'public';

    public const CLASSIFICATION_INTERNAL = 'internal';

    public const CLASSIFICATION_CONFIDENTIAL = 'confidential';

    public const CLASSIFICATION_PERSONAL = 'personal';

    public const CLASSIFICATION_SECRET = 'secret';

    public const CLASSIFICATION_UNVERIFIED = 'unverified';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    public const APPROVAL_DEPRECATED = 'deprecated';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'file_id',
        'owner_type',
        'owner_id',
        'name',
        'domain',
        'country_code',
        'publisher',
        'authority',
        'published_at',
        'effective_at',
        'fetched_at',
        'data_classification',
        'access_scope',
        'current_version',
        'change_detected',
        'is_active',
        'approval_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_scope' => 'array',
            'current_version' => 'integer',
            'change_detected' => 'boolean',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
            'effective_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(AiFile::class, 'file_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AiKnowledgeChunk::class, 'knowledge_source_id');
    }

    /**
     * Only an approved, active source is trusted as retrievable evidence
     * (v2.0 doc, 5.5: open/unreviewed content is never final evidence
     * without an approval policy).
     */
    public function isRetrievable(): bool
    {
        return $this->approval_status === self::APPROVAL_APPROVED && $this->is_active;
    }
}
