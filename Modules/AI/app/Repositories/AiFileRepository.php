<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\AI\Models\AiFile;

class AiFileRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['owner'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiFile $model)
    {
        $this->model = $model;
    }

    /**
     * Acceptance criteria doc S15/S23: the one place that enforces "User A
     * must never access User B's file" - mirrors
     * AiConversationRepository::findForOwner()'s exact pattern (scope the
     * query by owner, then findOrFail()), so a file belonging to another
     * owner 404s instead of leaking that the row exists (IDOR-safe, same
     * as the conversation endpoints already do).
     */
    public function findForOwner(Authenticatable $owner, int|string $id): AiFile
    {
        return $this->model->newQuery()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->findOrFail($id);
    }

    /**
     * Phase 12 (doc S7/S28): current file-count/storage-bytes totals for
     * one owner, used by AiFileEngine's upload-time quota check. Counts
     * every row regardless of processing_status - a failed upload still
     * occupies a disk slot/row until the owner removes it, so it must
     * still count toward the cap (otherwise repeatedly uploading files
     * that are rejected at validation would be a free, unbounded way to
     * fill storage without ever counting against the limit).
     *
     * @return array{files: int, bytes: int}
     */
    public function usageTotalsForOwner(Authenticatable $owner): array
    {
        $row = $this->model->newQuery()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->selectRaw('COUNT(*) as files_count, COALESCE(SUM(file_size), 0) as bytes_sum')
            ->first();

        return [
            'files' => (int) ($row->files_count ?? 0),
            'bytes' => (int) ($row->bytes_sum ?? 0),
        ];
    }
}
