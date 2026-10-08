<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationFile;
use Modules\AI\Models\AiFile;

/**
 * Phase 10 (doc S5): the one place that resolves "which files make up
 * this conversation's file context right now" and enforces the
 * attach/detach lifecycle - callers (AiChatService, the new
 * conversation-files API) never query `ai_conversation_files` or
 * `ai_files.conversation_id` directly.
 *
 * Reuses Phase 9's own authorization convention exactly
 * (owner_type/owner_id-scoped queries, never fetch-then-filter in PHP)
 * rather than introducing a second mechanism - doc S5's own
 * instruction ("must NOT duplicate Phase 9 authorization logic").
 *
 * Backward compatibility (doc S18): `ai_files.conversation_id` (Phase 1)
 * remains untouched and still means "the conversation this file was
 * first uploaded into." A file with no `ai_conversation_files` row at
 * all is treated as still attached to that original conversation (so
 * every file uploaded before this phase shipped keeps working exactly
 * as it did under Phase 9's own conversation-scope retrieval) - but the
 * moment a row exists for that (conversation, file) pair, that row's
 * `status` is the single source of truth, so a legacy file CAN be
 * explicitly detached.
 */
class AiConversationFileScope
{
    /**
     * Phase 10 (doc S30): attaching the same file to the same
     * conversation twice is a no-op, not a duplicate row - enforced here
     * via updateOrCreate() AND by the table's own unique constraint.
     * Reattaching a previously-detached file flips status back rather
     * than inserting a second row.
     */
    public function attach(AiConversation $conversation, AiFile $file, ?Authenticatable $attachedBy = null): AiConversationFile
    {
        return AiConversationFile::query()->updateOrCreate(
            ['conversation_id' => $conversation->id, 'file_id' => $file->id],
            [
                'status' => AiConversationFile::STATUS_ATTACHED,
                'attached_by_type' => $attachedBy?->getMorphClass(),
                'attached_by_id' => $attachedBy?->getAuthIdentifier(),
                'detached_at' => null,
            ],
        );
    }

    /**
     * Doc S15: detaching only removes the relationship - the underlying
     * AiFile row, its storage object, and its chunks/index are never
     * touched here. Returns false when the file was never part of this
     * conversation's scope at all (nothing to detach), so the caller can
     * 404 instead of silently succeeding on a no-op.
     */
    public function detach(AiConversation $conversation, AiFile $file): bool
    {
        if (! $this->isInScope($conversation, $file)) {
            return false;
        }

        AiConversationFile::query()->updateOrCreate(
            ['conversation_id' => $conversation->id, 'file_id' => $file->id],
            ['status' => AiConversationFile::STATUS_DETACHED, 'detached_at' => now()],
        );

        return true;
    }

    public function isInScope(AiConversation $conversation, AiFile $file): bool
    {
        return $this->attachedFileIds($conversation)->contains($file->id);
    }

    /**
     * Doc S4: every file currently attached (any processing_status) -
     * used by the listing API. "Attached" is not the same as
     * "searchable" - see resolveSearchableFileIds() below for the
     * narrower set retrieval actually uses.
     *
     * @return Collection<int, AiFile>
     */
    public function listAttachedFiles(Authenticatable $owner, AiConversation $conversation): Collection
    {
        $ids = $this->attachedFileIds($conversation);

        if ($ids->isEmpty()) {
            return collect();
        }

        return AiFile::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * Doc S14/S25/S26: resolves the authorized, currently SEARCHABLE
     * file id set for a retrieval call - every branch is owner-scoped
     * and status-scoped in the query itself, never fetch-then-filter.
     *
     * Doc S19 precedence: explicit file ids (when given, and non-empty)
     * take priority over the conversation's own attached file scope.
     * There is no separate "message-level attachment" tier in this
     * module's real architecture (see the Final Report's own
     * inspection) - a message-level attachment IS an AiFile with
     * `message_id` set, which already becomes part of the conversation
     * scope below the moment AiFileEngine::process() attaches it (doc
     * S18's own "do not silently change current behavior": the existing
     * per-message attachment flow is left exactly as it was, just now
     * ALSO registered in `ai_conversation_files`).
     *
     * @param  ?list<int>  $explicitFileIds
     * @return list<int>
     */
    public function resolveSearchableFileIds(Authenticatable $owner, AiConversation $conversation, ?array $explicitFileIds = null): array
    {
        if ($explicitFileIds !== null && $explicitFileIds !== []) {
            return $this->resolveExplicitFileIds($owner, $conversation, $explicitFileIds);
        }

        return $this->searchableFileIds($owner, $conversation, $this->attachedFileIds($conversation));
    }

    /**
     * Doc S28: never trust client-provided file ids. Every requested id
     * is re-checked against the owner AND against this conversation's
     * own scope - an id that resolves to someone else's file, or to a
     * file genuinely attached to a DIFFERENT conversation, is silently
     * dropped (doc S35: never leak via an error or a count) rather than
     * failing the whole request. An id for a file the owner has never
     * attached to ANY conversation (a standalone /ai-files upload) is
     * allowed and auto-attached here - letting a caller reference a
     * file they already uploaded without a separate attach round-trip.
     *
     * @param  list<int>  $explicitFileIds
     * @return list<int>
     */
    protected function resolveExplicitFileIds(Authenticatable $owner, AiConversation $conversation, array $explicitFileIds): array
    {
        $maxFiles = max(1, (int) config('ai.retrieval.multi_file.max_files', 10));

        $requestedIds = array_slice(array_values(array_unique(array_map('intval', $explicitFileIds))), 0, $maxFiles);

        if ($requestedIds === []) {
            return [];
        }

        $ownedReadyFiles = AiFile::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->where('processing_status', AiFile::STATUS_READY)
            ->whereIn('id', $requestedIds)
            ->get();

        $attachedElsewhere = AiConversationFile::query()
            ->where('status', AiConversationFile::STATUS_ATTACHED)
            ->where('conversation_id', '!=', $conversation->id)
            ->whereIn('file_id', $ownedReadyFiles->pluck('id'))
            ->pluck('file_id')
            ->all();

        $resolved = [];

        foreach ($ownedReadyFiles as $file) {
            $belongsToThisConversation = $this->isInScope($conversation, $file)
                || ($file->conversation_id === $conversation->id);

            $belongsToAnotherConversation = in_array($file->id, $attachedElsewhere, true)
                || ($file->conversation_id !== null && $file->conversation_id !== $conversation->id && ! $belongsToThisConversation);

            if ($belongsToAnotherConversation) {
                continue;
            }

            // Either already in this conversation's scope, or a
            // standalone file not attached anywhere - both are
            // explicitly permitted (doc S13.D). Auto-attach so it
            // becomes a durable part of this conversation going
            // forward, the same fix that closes the checksum-dedupe
            // gap documented in the Final Report.
            $this->attach($conversation, $file, $owner);
            $resolved[] = $file->id;
        }

        return $resolved;
    }

    /**
     * @param  Collection<int, int>  $candidateIds
     * @return list<int>
     */
    protected function searchableFileIds(Authenticatable $owner, AiConversation $conversation, Collection $candidateIds): array
    {
        if ($candidateIds->isEmpty()) {
            return [];
        }

        return AiFile::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->where('processing_status', AiFile::STATUS_READY)
            ->whereIn('id', $candidateIds)
            ->pluck('id')
            ->all();
    }

    /**
     * Doc S18's backward-compatibility rule: a legacy file (uploaded
     * before this phase, `conversation_id` set, no `ai_conversation_files`
     * row at all yet) still counts as attached. The moment a row exists
     * for that pair, its own `status` wins - so a legacy file CAN be
     * detached, and a detached legacy file never resurfaces via the
     * `conversation_id` fallback.
     *
     * @return Collection<int, int>
     */
    protected function attachedFileIds(AiConversation $conversation): Collection
    {
        $pivotRows = AiConversationFile::query()
            ->where('conversation_id', $conversation->id)
            ->get(['file_id', 'status']);

        $pivotAttached = $pivotRows->where('status', AiConversationFile::STATUS_ATTACHED)->pluck('file_id');
        $pivotKnownIds = $pivotRows->pluck('file_id');

        $legacyFileIds = AiFile::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotIn('id', $pivotKnownIds->all() ?: [0])
            ->pluck('id');

        return $pivotAttached->merge($legacyFileIds)->unique()->values();
    }
}
