import { ref } from 'vue';

/**
 * Phase 11 (doc S4/S6/S8/S9/S10/S12): manages the "files attached to
 * this CONVERSATION" list - distinct from a single quick attachment
 * sent inline with one message (see ChatPanel.vue's own paperclip
 * button, untouched by this composable). Wraps the Phase 10 APIs:
 *   GET/POST/DELETE  /user/v1/ai-chat/conversations/{id}/files   (scope)
 *   POST             /user/v1/ai-files                           (upload)
 *   GET              /user/v1/ai-files/{id}/status                (poll)
 *
 * Uploading a file with conversation_id already attaches it server-side
 * (AiFileEngine::attachToConversation(), Phase 10) - there is no
 * separate "upload" then "attach" step to orchestrate here, only
 * "upload, then poll until it leaves PROCESSING".
 *
 * Deliberately does not touch chunking/embeddings/retrieval-mode
 * concepts anywhere - this composable only ever sees
 * id/file_name/file_size/mime_type/status (doc S2: keep the technical
 * pipeline invisible).
 *
 * @param {import('axios').AxiosInstance} axios
 */
export function useAiConversationFiles(axios) {
    const files = ref([]);
    const loadingFiles = ref(false);
    const selectedFileIds = ref([]); // doc S12: explicit per-message scope, empty = "use all"

    const pollTimers = new Map();
    const POLL_INTERVAL_MS = 2500;
    const POLL_TIMEOUT_MS = 2 * 60 * 1000;

    function isTerminal(status) {
        return status === 'ready' || status === 'failed' || status === 'detached';
    }

    function stopPolling(fileId) {
        const timer = pollTimers.get(fileId);

        if (timer) {
            clearTimeout(timer.handle);
            pollTimers.delete(fileId);
        }
    }

    function stopAllPolling() {
        pollTimers.forEach((timer) => clearTimeout(timer.handle));
        pollTimers.clear();
    }

    function upsertFile(entry) {
        const index = files.value.findIndex((f) => f.id === entry.id);

        if (index === -1) {
            files.value.push(entry);
        } else {
            files.value[index] = { ...files.value[index], ...entry };
        }
    }

    function removeFromList(fileId) {
        files.value = files.value.filter((f) => f.id !== fileId);
        selectedFileIds.value = selectedFileIds.value.filter((id) => id !== fileId);
    }

    async function loadFiles(conversationId) {
        if (!conversationId) {
            files.value = [];
            return;
        }

        loadingFiles.value = true;

        try {
            const { data } = await axios.get(`/api/user/v1/ai-chat/conversations/${conversationId}/files`);
            const rows = (data.data ?? []).map((row) => ({
                id: row.id,
                file_name: row.file_name,
                file_size: row.file_size,
                mime_type: row.mime_type,
                status: row.processing_status, // backend values: uploading/processing/ready/failed (AiFile lifecycle)
                processing_error: row.processing_error,
            }));

            files.value = rows;

            // Doc S30: keep updating without a page reload - any file
            // still mid-flight (not yet ready/failed) keeps being polled.
            rows.filter((r) => !isTerminal(r.status)).forEach((r) => schedulePoll(conversationId, r.id));
        } catch (error) {
            // Non-fatal - the conversation files panel simply stays
            // empty/stale if this fails; the chat itself keeps working.
        } finally {
            loadingFiles.value = false;
        }
    }

    function schedulePoll(conversationId, fileId, startedAt = Date.now()) {
        stopPolling(fileId);

        const handle = setTimeout(async () => {
            if (Date.now() - startedAt > POLL_TIMEOUT_MS) {
                // Doc S9 "avoid aggressive polling / stop at terminal
                // states" - also stop if something is stuck far longer
                // than any real processing job should take, rather than
                // polling forever on a lost job.
                upsertFile({ id: fileId, status: 'failed', processing_error: 'timed_out' });
                return;
            }

            try {
                const { data } = await axios.get(`/api/user/v1/ai-files/${fileId}/status`);
                const status = data.data?.processing_status ?? data.data?.status;

                upsertFile({ id: fileId, status, processing_error: data.data?.processing_error ?? null });

                if (!isTerminal(status)) {
                    schedulePoll(conversationId, fileId, startedAt);
                } else {
                    pollTimers.delete(fileId);
                }
            } catch (error) {
                // A transient network error while polling shouldn't flip
                // the file to "failed" - just try again next tick.
                schedulePoll(conversationId, fileId, startedAt);
            }
        }, POLL_INTERVAL_MS);

        pollTimers.set(fileId, { handle });
    }

    /**
     * @param {number|string} conversationId
     * @param {File[]} fileList
     * @param {{max_size_bytes?: number, allowed_mime_types?: string[], max_conversation_files?: number}} limits
     * @returns {{accepted: number, rejected: {name: string, reason: string}[]}}
     */
    async function uploadFiles(conversationId, fileList, limits = {}) {
        const rejected = [];
        const toUpload = [];

        const remainingSlots = limits.max_conversation_files
            ? Math.max(0, limits.max_conversation_files - files.value.length)
            : fileList.length;

        Array.from(fileList).forEach((file, index) => {
            if (limits.max_size_bytes && file.size > limits.max_size_bytes) {
                rejected.push({ name: file.name, reason: 'file_too_large' });
                return;
            }

            if (index >= remainingSlots) {
                rejected.push({ name: file.name, reason: 'max_files_reached' });
                return;
            }

            toUpload.push(file);
        });

        for (const file of toUpload) {
            const localId = `pending-${Date.now()}-${Math.random().toString(16).slice(2)}`;

            upsertFile({
                id: localId,
                file_name: file.name,
                file_size: file.size,
                mime_type: file.type,
                status: 'uploading',
            });

            try {
                const payload = new FormData();
                payload.append('file', file);
                payload.append('conversation_id', conversationId);

                const { data } = await axios.post('/api/user/v1/ai-files', payload, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });

                const row = data.data;
                removeFromList(localId);
                upsertFile({
                    id: row.id,
                    file_name: row.file_name,
                    file_size: row.file_size,
                    mime_type: row.mime_type,
                    status: row.processing_status,
                    processing_error: row.processing_error,
                });

                if (!isTerminal(row.processing_status)) {
                    schedulePoll(conversationId, row.id);
                }
            } catch (error) {
                removeFromList(localId);
                rejected.push({
                    name: file.name,
                    reason: error.response?.status === 422 ? 'unsupported_file_type' : 'upload_failed',
                });
            }
        }

        return { accepted: toUpload.length, rejected };
    }

    async function removeFile(conversationId, fileId) {
        stopPolling(fileId);

        try {
            await axios.delete(`/api/user/v1/ai-chat/conversations/${conversationId}/files/${fileId}`);
            removeFromList(fileId);

            return true;
        } catch (error) {
            return false;
        }
    }

    function toggleSelected(fileId) {
        const index = selectedFileIds.value.indexOf(fileId);

        if (index === -1) {
            selectedFileIds.value = [...selectedFileIds.value, fileId];
        } else {
            selectedFileIds.value = selectedFileIds.value.filter((id) => id !== fileId);
        }
    }

    function clearSelection() {
        selectedFileIds.value = [];
    }

    function reset() {
        stopAllPolling();
        files.value = [];
        selectedFileIds.value = [];
    }

    return {
        files,
        loadingFiles,
        selectedFileIds,
        loadFiles,
        uploadFiles,
        removeFile,
        toggleSelected,
        clearSelection,
        reset,
        stopAllPolling,
    };
}
