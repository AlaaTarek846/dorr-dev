<template>
    <div class="chat-panel d-flex flex-column">
        <div v-if="!hasConversation" class="flex-fill d-flex align-items-center justify-content-center text-center p-4">
            <div>
                <span class="avatar avatar-xl avatar-rounded bg-primary-transparent mb-3">
                    <i class="ri-sparkling-2-fill fs-24 text-primary"></i>
                </span>
                <p class="fw-semibold mb-1">{{ t('ai_chat.empty_state_title') }}</p>
                <p class="text-muted mb-0">{{ t('ai_chat.empty_state_subtitle') }}</p>
            </div>
        </div>

        <template v-else>
            <div v-if="!available" class="alert alert-warning d-flex align-items-start gap-2 m-3 mb-0">
                <i class="ri-error-warning-line fs-18 mt-1"></i>
                <div>
                    <strong class="d-block">{{ t('ai_chat.unavailable_title') }}</strong>
                    <span class="fs-12">{{ t('ai_chat.unavailable_message') }}</span>
                </div>
            </div>

            <div class="chat-panel__header border-bottom px-3 py-2 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <span class="fs-12 text-muted">
                    <i class="ri-verified-badge-fill align-middle me-1 text-primary"></i>
                    {{ t('ai_chat.powered_by', { brand: brandName }) }}
                </span>

                <span v-if="usageLabel" class="badge fs-11 fw-medium" :class="usageBadgeClass">
                    <i class="ri-time-line align-middle me-1"></i>{{ usageLabel }}
                </span>
            </div>

            <div ref="messagesEl" class="chat-panel__messages flex-fill overflow-auto p-3">
                <div
                    v-for="message in messages"
                    :key="message.id"
                    class="chat-bubble-row"
                    :class="message.role === 'user' ? 'chat-bubble-row--user' : 'chat-bubble-row--assistant'"
                >
                    <span
                        v-if="message.role !== 'user'"
                        class="chat-bubble__avatar avatar avatar-sm avatar-rounded bg-primary-transparent"
                    >
                        <i class="ri-sparkling-2-fill text-primary"></i>
                    </span>

                    <div
                        class="chat-bubble"
                        :class="{
                            'chat-bubble--user': message.role === 'user',
                            'chat-bubble--error': message.is_error,
                        }"
                    >
                        <div class="chat-bubble__meta fs-11 mb-1">
                            {{ message.role === 'user' ? t('ai_chat.you') : brandName }}
                        </div>

                        <div
                            v-for="attachment in (message.attachments || [])"
                            :key="attachment.id"
                            class="chat-attachment mb-2"
                        >
                            <a v-if="attachment.is_image" :href="attachment.url" target="_blank" rel="noopener">
                                <img :src="attachment.url" :alt="attachment.file_name" class="chat-attachment__image">
                            </a>
                            <a v-else :href="attachment.url" target="_blank" rel="noopener" class="chat-attachment__file">
                                <i class="ri-file-line fs-18"></i>
                                <span class="text-truncate">{{ attachment.file_name }}</span>
                                <i class="ri-download-2-line"></i>
                            </a>
                        </div>

                        <div class="chat-bubble__content" v-html="formatContent(message.content)"></div>

                        <a
                            v-if="message.generated_file"
                            :href="message.generated_file.url"
                            target="_blank"
                            rel="noopener"
                            class="chat-generated-file mt-2"
                        >
                            <i class="ri-file-download-line fs-18"></i>
                            <span class="text-truncate">{{ message.generated_file.name }}</span>
                            <span class="fs-11 opacity-75">{{ t('ai_chat.download_file') }}</span>
                        </a>

                        <div
                            v-if="message.verification_warnings && message.verification_warnings.length"
                            class="chat-confidence-notice mt-2"
                        >
                            <i class="ri-error-warning-line fs-14"></i>
                            <span>{{ message.verification_warnings[0] }}</span>
                        </div>

                        <div v-if="message.role !== 'user' && message.model" class="chat-bubble__answered-by fs-10 text-muted mt-2">
                            <i class="ri-cpu-line align-middle me-1"></i>
                            {{ t('ai_chat.answered_by', { provider: providerLabel(message.provider_key), model: message.model }) }}
                        </div>
                    </div>
                </div>

                <div v-if="sending" class="chat-bubble-row chat-bubble-row--assistant">
                    <span class="chat-bubble__avatar avatar avatar-sm avatar-rounded bg-primary-transparent">
                        <i class="ri-sparkling-2-fill text-primary"></i>
                    </span>
                    <div v-if="streamingText" class="chat-bubble" v-html="formatContent(streamingText)"></div>
                    <div v-else class="chat-bubble chat-bubble--typing">
                        <span class="typing-dot"></span>
                        <span class="typing-dot"></span>
                        <span class="typing-dot"></span>
                    </div>
                    <button
                        type="button"
                        class="btn btn-sm btn-light chat-panel__cancel ms-2"
                        :title="t('ai_chat.cancel_sending')"
                        @click="emit('cancel')"
                    >
                        <i class="ri-stop-circle-line"></i>
                        {{ t('ai_chat.cancel_sending') }}
                    </button>
                </div>
            </div>

            <form class="chat-panel__composer border-top p-2 p-md-3" @submit.prevent="submit">
                <div v-if="attachmentFile" class="chat-panel__attachment-preview mb-2">
                    <i class="ri-attachment-2 fs-16"></i>
                    <span class="text-truncate">{{ attachmentFile.name }}</span>
                    <button type="button" class="btn-close btn-close-sm" @click="clearAttachment"></button>
                </div>

                <div class="chat-panel__composer-inner">
                    <input
                        ref="fileInput"
                        type="file"
                        class="d-none"
                        accept="image/png,image/jpeg,image/gif,image/webp,.pdf,.doc,.docx,.txt,.csv,.xlsx"
                        @change="onFileSelected"
                    >
                    <button
                        type="button"
                        class="btn btn-icon chat-panel__attach"
                        :disabled="!available || sending"
                        :title="t('ai_chat.attach_file')"
                        @click="fileInput.click()"
                    >
                        <i class="ri-attachment-2 fs-18"></i>
                    </button>

                    <textarea
                        v-model="draft"
                        rows="1"
                        class="form-control chat-panel__textarea"
                        :placeholder="t('ai_chat.message_placeholder')"
                        :disabled="!available || sending"
                        @keydown.enter.exact.prevent="submit"
                    ></textarea>
                    <button
                        v-if="sending"
                        type="button"
                        class="btn btn-danger chat-panel__send"
                        :title="t('ai_chat.cancel_sending')"
                        @click="emit('cancel')"
                    >
                        <i class="ri-stop-circle-line"></i>
                    </button>
                    <button
                        v-else
                        type="submit"
                        class="btn btn-primary chat-panel__send"
                        :disabled="!available || (!draft.trim() && !attachmentFile)"
                    >
                        <i class="ri-send-plane-2-fill"></i>
                    </button>
                </div>
            </form>
        </template>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    hasConversation: {
        type: Boolean,
        default: false,
    },
    messages: {
        type: Array,
        default: () => [],
    },
    available: {
        type: Boolean,
        default: true,
    },
    sending: {
        type: Boolean,
        default: false,
    },
    brandName: {
        type: String,
        default: 'DORR AI',
    },
    usage: {
        type: Object,
        default: null,
    },
    streamingText: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['send', 'cancel', 'usage-expired']);

const { t } = useI18n();
const draft = ref('');
const messagesEl = ref(null);
const fileInput = ref(null);
const attachmentFile = ref(null);

function scrollToBottom() {
    nextTick(() => {
        if (messagesEl.value) {
            messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
        }
    });
}

watch(() => props.messages.length, scrollToBottom);
watch(() => props.sending, scrollToBottom);
watch(() => props.hasConversation, scrollToBottom);

function onFileSelected(event) {
    const file = event.target.files?.[0];
    attachmentFile.value = file || null;
}

function clearAttachment() {
    attachmentFile.value = null;
    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

// Very small, safe subset of markdown so replies with headings/lists/bold
// text (something models produce constantly) render nicely instead of as
// raw asterisks/hashes - never trusts the string as HTML beyond this.
// Small, human-readable label for whichever provider actually answered -
// the routing engine already picks the right model per message (capability
// matching, fallback chains, etc.), this just makes that pick visible in
// the UI instead of being invisible backend-only behaviour.
const PROVIDER_LABELS = {
    openai: 'OpenAI',
    anthropic: 'Anthropic',
    google: 'Google',
    groq: 'Groq',
};

function providerLabel(providerKey) {
    return PROVIDER_LABELS[providerKey] || providerKey || '';
}

function formatContent(content) {
    if (! content) return '';

    const escaped = content
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    return escaped
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/^### (.*)$/gm, '<strong>$1</strong>')
        .replace(/^- (.*)$/gm, '&bull;&nbsp;$1')
        .replace(/\n/g, '<br>');
}

function submit() {
    const message = draft.value.trim();

    if ((! message && ! attachmentFile.value) || props.sending || ! props.available) {
        return;
    }

    emit('send', { message, attachment: attachmentFile.value });
    draft.value = '';
    clearAttachment();
}

// Live, ticking countdown rather than a number that only changes when a
// fresh API response happens to arrive. tickingRemaining is the single
// source of truth for what the badge displays; it is re-synced from the
// server's own remaining_seconds every time a fresh `usage` prop lands
// (page load, after sending a message, or the periodic resync below),
// and ticks down locally once a second in between so the user sees an
// actually-moving clock instead of a frozen number. Ticking locally
// (instead of polling the server every second) also respects the
// ai-chat-general rate limiter added for S15.4 - one request per sync,
// not sixty per minute just to move a clock.
const tickingRemaining = ref(null);
let tickIntervalId = null;

watch(
    () => props.usage?.remaining_seconds,
    (fresh) => {
        tickingRemaining.value = (fresh === null || fresh === undefined) ? null : Math.floor(fresh);
    },
    { immediate: true },
);

onMounted(() => {
    tickIntervalId = setInterval(() => {
        if (tickingRemaining.value === null || tickingRemaining.value <= 0) {
            return;
        }

        tickingRemaining.value -= 1;

        if (tickingRemaining.value === 0) {
            // The trial/plan window just ran out from the client's point
            // of view - ask the server what actually happens next
            // (cooldown started? plan renewed?) instead of guessing, and
            // instead of leaving the badge stuck at a false "0:00"
            // forever if the server disagrees.
            emit('usage-expired');
        }
    }, 1000);
});

onBeforeUnmount(() => {
    if (tickIntervalId !== null) {
        clearInterval(tickIntervalId);
    }
});

const usageLabel = computed(() => {
    if (! props.usage || tickingRemaining.value === null) return null;

    const wholeSeconds = Math.max(0, tickingRemaining.value);
    const minutes = Math.floor(wholeSeconds / 60);
    const seconds = String(wholeSeconds % 60).padStart(2, '0');
    const time = `${minutes}:${seconds}`;

    return props.usage.plan_is_trial ? t('ai_chat.trial_time_left', { time }) : t('ai_chat.time_left', { time });
});

const usageBadgeClass = computed(() => {
    if (! props.usage) return 'bg-secondary-transparent';

    const remaining = tickingRemaining.value;

    if (remaining !== null && remaining !== undefined && remaining < 60) {
        return 'bg-danger-transparent';
    }

    return props.usage.plan_is_trial ? 'bg-warning-transparent' : 'bg-success-transparent';
});
</script>

<style scoped>
.chat-panel {
    height: 100%;
    min-height: 0;
}

.chat-panel__header {
    flex: 0 0 auto;
}

.chat-panel__messages {
    min-height: 0;
}

.chat-bubble-row {
    display: flex;
    align-items: flex-end;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.chat-bubble-row--user {
    justify-content: flex-end;
}

.chat-bubble-row--assistant {
    justify-content: flex-start;
}

.chat-bubble__avatar {
    flex: 0 0 auto;
    width: 1.75rem;
    height: 1.75rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
}

.chat-bubble {
    max-width: 75%;
    padding: 0.6rem 0.9rem;
    border-radius: 0.9rem;
    background-color: var(--default-background, #f4f6f9);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
    word-break: break-word;
}

.chat-bubble__content {
    white-space: normal;
}

.chat-bubble--user {
    background-color: var(--bs-primary, #377dff);
    color: #fff;
}

.chat-bubble--user .chat-bubble__meta {
    color: rgba(255, 255, 255, 0.75);
}

.chat-bubble--error {
    background-color: rgba(220, 53, 69, 0.1);
    color: var(--bs-danger, #dc3545);
}

.chat-bubble__meta {
    color: var(--text-muted, #8c9097);
}

.chat-bubble--typing {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.75rem 1rem;
}

.typing-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background-color: var(--text-muted, #8c9097);
    animation: chat-typing 1.2s infinite ease-in-out;
}

.typing-dot:nth-child(2) {
    animation-delay: 0.2s;
}

.typing-dot:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes chat-typing {
    0%, 60%, 100% {
        opacity: 0.3;
        transform: translateY(0);
    }

    30% {
        opacity: 1;
        transform: translateY(-3px);
    }
}

.chat-attachment__image {
    max-width: 220px;
    max-height: 220px;
    border-radius: 0.6rem;
    display: block;
}

.chat-attachment__file,
.chat-generated-file {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.6rem;
    border-radius: 0.6rem;
    background-color: rgba(0, 0, 0, 0.05);
    color: inherit;
    text-decoration: none;
    max-width: 220px;
}

.chat-bubble--user .chat-attachment__file,
.chat-bubble--user .chat-generated-file {
    background-color: rgba(255, 255, 255, 0.18);
    color: #fff;
}

.chat-confidence-notice {
    display: flex;
    align-items: flex-start;
    gap: 0.4rem;
    padding: 0.4rem 0.6rem;
    border-radius: 0.6rem;
    background-color: rgba(255, 193, 7, 0.15);
    color: #a06600;
    font-size: 0.75rem;
    line-height: 1.4;
}

.chat-bubble--user .chat-confidence-notice {
    background-color: rgba(255, 255, 255, 0.18);
    color: #fff;
}

.chat-panel__composer {
    flex: 0 0 auto;
}

.chat-panel__attachment-preview {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background-color: var(--default-background, #f4f6f9);
    border-radius: 0.6rem;
    padding: 0.3rem 0.6rem;
    max-width: 260px;
}

.chat-panel__composer-inner {
    display: flex;
    align-items: flex-end;
    gap: 0.5rem;
}

.chat-panel__attach {
    flex: 0 0 auto;
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: 1px solid var(--default-border, #e9edf1);
    background: transparent;
}

.chat-panel__textarea {
    resize: none;
    max-height: 8rem;
    border-radius: 1.25rem;
}

.chat-panel__send {
    flex: 0 0 auto;
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}
</style>
