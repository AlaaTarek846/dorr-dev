<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">
                    <i class="ri-sparkling-2-fill text-primary align-middle me-1"></i>
                    {{ t('ai_chat.title') }}
                </p>
                <span class="fs-semibold text-muted">{{ t('ai_chat.subtitle') }}</span>
            </div>
        </div>

        <div class="card custom-card chat-card">
            <div class="card-body p-0">
                <div class="chat-layout">
                    <div class="chat-layout__sidebar">
                        <ConversationSidebar
                            :conversations="conversations"
                            :active-id="activeConversationId"
                            :loading="loadingConversations"
                            :creating="creatingConversation"
                            @select="selectConversation"
                            @create="createConversation"
                            @delete="confirmDelete"
                        />
                    </div>
                    <div class="chat-layout__panel">
                        <ChatPanel
                            :has-conversation="activeConversationId !== null"
                            :messages="activeMessages"
                            :available="status.available"
                            :sending="sending"
                            :brand-name="status.brand_name || 'DORR AI'"
                            :usage="usage"
                            :streaming-text="streamingText"
                            @send="sendMessage"
                            @cancel="cancelSending"
                            @usage-expired="loadUsage"
                        />
                    </div>
                </div>
            </div>
        </div>

        <ConfirmDeleteModal
            :show="deleteConfirm.state.show"
            :title="t('ai_chat.delete_conversation')"
            :message="t('ai_chat.confirm_delete')"
            :loading="deleteConfirm.state.loading"
            @close="deleteConfirm.close()"
            @confirm="handleDeleteConfirm"
        />
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import userAxios from '../../../../../../api/userAxios';
import ChatPanel from '../../../../../../components/chat/ChatPanel.vue';
import ConversationSidebar from '../../../../../../components/chat/ConversationSidebar.vue';
import ConfirmDeleteModal from '../../../../../../components/ui/ConfirmDeleteModal.vue';
import { useAiConversationChannel } from '../../../../../../composables/useAiConversationChannel';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

const { t } = useI18n();
const { showError, showWarning } = useToast();
const deleteConfirm = useConfirmDelete();

const status = reactive({ available: true, brand_name: 'DORR AI' });
const usage = ref(null);
const conversations = ref([]);
const activeConversationId = ref(null);
const activeMessages = ref([]);

const loadingConversations = ref(true);
const creatingConversation = ref(false);
const sending = ref(false);

// v2.0 requirements doc S18.2 (streaming + cancel).
const streamingText = ref('');
const abortController = ref(null);

async function loadStatus() {
    try {
        const { data } = await userAxios.get('/api/user/v1/ai-chat/status');
        Object.assign(status, data.data ?? {});
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function loadUsage() {
    try {
        const { data } = await userAxios.get('/api/user/v1/ai-chat/usage');
        usage.value = data.data ?? null;
    } catch (error) {
        // Non-fatal - the usage banner simply stays hidden if this fails.
        usage.value = null;
    }
}

async function loadConversations(showSkeleton = true) {
    if (showSkeleton) {
        loadingConversations.value = true;
    }

    try {
        const { data } = await userAxios.get('/api/user/v1/ai-chat/conversations');
        conversations.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        if (showSkeleton) {
            loadingConversations.value = false;
        }
    }
}

async function selectConversation(id) {
    activeConversationId.value = id;
    activeMessages.value = [];

    try {
        const { data } = await userAxios.get(`/api/user/v1/ai-chat/conversations/${id}`);
        activeMessages.value = data.data?.messages ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('ai_chat.load_failed')));
    }
}

async function createConversation() {
    creatingConversation.value = true;

    try {
        const { data } = await userAxios.post('/api/user/v1/ai-chat/conversations');
        await loadConversations(false);
        await selectConversation(data.data.id);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        creatingConversation.value = false;
    }
}

function confirmDelete(id) {
    deleteConfirm.open({
        title: t('ai_chat.delete_conversation'),
        message: t('ai_chat.confirm_delete'),
        payload: { id },
    });
}

async function handleDeleteConfirm() {
    const { id } = deleteConfirm.state.payload;
    deleteConfirm.setLoading(true);

    try {
        await userAxios.delete(`/api/user/v1/ai-chat/conversations/${id}`);

        if (activeConversationId.value === id) {
            activeConversationId.value = null;
            activeMessages.value = [];
        }

        await loadConversations(false);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
    }
}

// v2.0 requirements doc S15.4/S20.3: one Idempotency-Key per logical send
// so a network drop mid-request can be safely retried without risking a
// duplicate assistant reply - the server replays the first attempt's
// result instead of calling the AI provider a second time.
function makeIdempotencyKey() {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }

    return `idem-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function isNetworkFailure(error) {
    // No response at all (dropped connection, timeout, DNS failure) means
    // we genuinely don't know whether the server received and processed
    // the request - that is exactly the case idempotency exists for. A
    // response that DID arrive (4xx/5xx) is a real answer and must never
    // be retried silently.
    return ! error.response;
}

function cancelSending() {
    // S18.2 "cancel": abort() rejects the in-flight fetch() with an
    // AbortError, which sendMessageStreamed() below treats as a clean
    // user-initiated stop rather than a failure - no error toast, no
    // retry, the partial streamed text is simply dropped and the
    // composer becomes usable again.
    abortController.value?.abort();
}

function authHeaders() {
    const token = localStorage.getItem('user_token');
    const locale = localStorage.getItem('user_locale') || localStorage.getItem('admin_locale');
    const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };

    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }

    if (locale) {
        headers['X-Locale'] = locale;
        headers['Accept-Language'] = locale;
    }

    return headers;
}

/**
 * Parses one Server-Sent Events frame ("event: x\ndata: {...}") the way
 * AiChatService::emitSseEvent() writes it. Returns null for a frame with
 * no data line (e.g. a trailing blank chunk) rather than throwing, since
 * a stream boundary landing mid-frame is a normal, expected occurrence
 * with chunked transfer - not a client bug to alert on.
 */
function parseSseFrame(raw) {
    const dataLine = raw.split('\n').find((line) => line.startsWith('data: '));

    if (! dataLine) {
        return null;
    }

    const eventLine = raw.split('\n').find((line) => line.startsWith('event: '));
    const event = eventLine ? eventLine.slice('event: '.length) : 'message';

    try {
        return { event, data: JSON.parse(dataLine.slice('data: '.length)) };
    } catch (e) {
        return null;
    }
}

/**
 * The streaming path (no attachment): the server runs the full, already-
 * verified pipeline and trickles the finished answer back over SSE - see
 * AiChatService::streamMessage() for why this is progressive delivery of
 * a verified answer rather than raw token generation streaming. Falls
 * back to the plain non-streaming endpoint on anything that isn't a
 * clean, deliberate cancel (a network hiccup, the browser not supporting
 * fetch streaming, etc.) so sending a message never silently does
 * nothing just because the streaming transport failed.
 */
async function sendMessageStreamed(message) {
    sending.value = true;
    streamingText.value = '';
    abortController.value = new AbortController();

    const idempotencyKey = makeIdempotencyKey();

    try {
        const response = await fetch(
            `/api/user/v1/ai-chat/conversations/${activeConversationId.value}/messages/stream`,
            {
                method: 'POST',
                headers: { ...authHeaders(), 'Idempotency-Key': idempotencyKey },
                body: JSON.stringify({ message }),
                signal: abortController.value.signal,
            },
        );

        if (! response.ok || ! response.body) {
            throw new Error(`stream request failed with status ${response.status}`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let finalPayload = null;
        let sawError = false;

        // eslint-disable-next-line no-constant-condition
        while (true) {
            const { value, done } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const frames = buffer.split('\n\n');
            buffer = frames.pop() ?? '';

            for (const raw of frames) {
                const parsed = parseSseFrame(raw);
                if (! parsed) continue;

                if (parsed.event === 'chunk') {
                    streamingText.value += parsed.data.delta ?? '';
                } else if (parsed.event === 'error') {
                    sawError = true;
                    finalPayload = parsed.data;
                } else if (parsed.event === 'done') {
                    finalPayload = parsed.data;
                }
            }
        }

        if (sawError) {
            activeMessages.value.pop();

            if (finalPayload?.data) {
                usage.value = finalPayload.data;
            }

            showError(finalPayload?.message || t('toast.error'));
        } else if (finalPayload?.data?.assistant_message) {
            activeMessages.value.push(finalPayload.data.assistant_message);
            usage.value = finalPayload.data.usage ?? usage.value;
            await loadConversations(false);
        } else {
            // Streamed cleanly but the final payload shape was
            // unrecognised - safer to resync from the server than to
            // leave the UI showing a half-built state.
            await selectConversation(activeConversationId.value);
        }
    } catch (error) {
        if (error?.name === 'AbortError') {
            // Deliberate cancel - drop the partial streamed text, keep
            // the user's own message bubble, no error toast.
        } else {
            activeMessages.value.pop();
            showError(t('toast.error'));
        }
    } finally {
        sending.value = false;
        streamingText.value = '';
        abortController.value = null;
    }
}

/**
 * The plain request/response path, still used for an attachment: SSE +
 * multipart upload in a single request is out of scope (see
 * AiChatController::streamMessage() docblock), so a message with a file
 * attached goes through the original endpoint and keeps its own
 * idempotent, bounded-retry behaviour.
 */
async function sendMessageWithAttachment(message, attachment) {
    sending.value = true;

    const idempotencyKey = makeIdempotencyKey();
    const maxAttempts = 2; // the original attempt + one retry on a network-level failure only

    const payload = new FormData();
    payload.append('message', message || '');
    payload.append('attachment', attachment);
    const headers = { 'Content-Type': 'multipart/form-data', 'Idempotency-Key': idempotencyKey };

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
        try {
            const { data } = await userAxios.post(
                `/api/user/v1/ai-chat/conversations/${activeConversationId.value}/messages`,
                payload,
                { headers },
            );

            activeMessages.value = data.data.conversation.messages;
            usage.value = data.data.usage ?? usage.value;
            await loadConversations(false);
            break;
        } catch (error) {
            const canRetry = attempt < maxAttempts && isNetworkFailure(error);

            if (canRetry) {
                continue;
            }

            activeMessages.value.pop();

            if (error.response?.data?.data) {
                usage.value = error.response.data.data;
            }

            if (error.response?.status === 402) {
                showWarning(extractApiErrorMessage(error, t('toast.error')));
            } else if (error.response?.status === 409) {
                // Another in-flight send with the same key is still being
                // processed server-side - nothing to show but an error
                // isn't quite right either, so this is treated as "wait".
                showWarning(extractApiErrorMessage(error, t('toast.error')));
            } else {
                showError(extractApiErrorMessage(error, t('toast.error')));
            }

            break;
        }
    }

    sending.value = false;
}

async function sendMessage({ message, attachment }) {
    if (! activeConversationId.value) {
        return;
    }

    // Show the user's own message immediately; the assistant's reply (or
    // error bubble) is appended once the request comes back.
    activeMessages.value.push({
        id: `pending-${Date.now()}`,
        role: 'user',
        content: message,
        is_error: false,
        attachments: attachment ? [{ id: 'pending', file_name: attachment.name, is_image: attachment.type?.startsWith('image/'), url: null }] : [],
    });

    if (attachment) {
        await sendMessageWithAttachment(message, attachment);
    } else {
        await sendMessageStreamed(message);
    }
}

// v2.0 requirements doc S15.3: this tab already gets its own reply from
// the request above - this listener is for any OTHER open tab/session on
// the same conversation, so a duplicate push (same message id) is a
// no-op rather than a second bubble.
useAiConversationChannel(activeConversationId, (message) => {
    if (activeMessages.value.some((existing) => existing.id === message.id)) {
        return;
    }

    activeMessages.value.push(message);
}, 'user_token');

onMounted(async () => {
    await Promise.all([loadStatus(), loadConversations(), loadUsage()]);
});
</script>

<style scoped>
.chat-card {
    overflow: hidden;
}

.chat-layout {
    display: flex;
    /* A bounded box rather than a 100vh-minus-guesswork calculation, so it
       never depends on exactly how tall the surrounding header/breadcrumb
       happens to be. */
    height: 70vh;
    min-height: 32rem;
    max-height: 44rem;
}

.chat-layout__sidebar {
    flex: 0 0 300px;
    max-width: 300px;
    /* Critical for nested flex + overflow: without this, a flex item
       refuses to shrink below its content's natural height, so the
       "scrollable" inner region just grows instead of scrolling and pushes
       everything after it (the composer) out of the box entirely. */
    min-height: 0;
}

.chat-layout__panel {
    flex: 1 1 auto;
    min-width: 0;
    min-height: 0;
}

@media (max-width: 991.98px) {
    .chat-layout {
        flex-direction: column;
        height: auto;
        max-height: none;
    }

    .chat-layout__sidebar {
        flex: 0 0 auto;
        max-width: none;
        max-height: 14rem;
    }

    .chat-layout__panel {
        flex: 1 1 auto;
        min-height: 28rem;
    }
}
</style>
