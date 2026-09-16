<template>
    <div class="chat-panel d-flex flex-column">
        <div v-if="!hasConversation" class="flex-fill d-flex align-items-center justify-content-center text-center p-4">
            <div>
                <span class="avatar avatar-xl avatar-rounded bg-primary-transparent mb-3">
                    <i class="ri-robot-2-line fs-24 text-primary"></i>
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
            <div v-else-if="providerName" class="chat-panel__header border-bottom px-3 py-2 fs-12 text-muted">
                <i class="ri-sparkling-2-line align-middle me-1"></i>
                {{ t('ai_chat.powered_by', { provider: providerName }) }}
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
                        <i class="ri-robot-2-line text-primary"></i>
                    </span>

                    <div
                        class="chat-bubble"
                        :class="{
                            'chat-bubble--user': message.role === 'user',
                            'chat-bubble--error': message.is_error,
                        }"
                    >
                        <div class="chat-bubble__meta fs-11 mb-1">
                            {{ message.role === 'user' ? t('ai_chat.you') : t('ai_chat.assistant') }}
                        </div>
                        <div class="chat-bubble__content">{{ message.content }}</div>
                    </div>
                </div>

                <div v-if="sending" class="chat-bubble-row chat-bubble-row--assistant">
                    <span class="chat-bubble__avatar avatar avatar-sm avatar-rounded bg-primary-transparent">
                        <i class="ri-robot-2-line text-primary"></i>
                    </span>
                    <div class="chat-bubble chat-bubble--typing">
                        <span class="typing-dot"></span>
                        <span class="typing-dot"></span>
                        <span class="typing-dot"></span>
                    </div>
                </div>
            </div>

            <form class="chat-panel__composer border-top p-2 p-md-3" @submit.prevent="submit">
                <div class="chat-panel__composer-inner">
                    <textarea
                        v-model="draft"
                        rows="1"
                        class="form-control chat-panel__textarea"
                        :placeholder="t('ai_chat.message_placeholder')"
                        :disabled="!available || sending"
                        @keydown.enter.exact.prevent="submit"
                    ></textarea>
                    <button
                        type="submit"
                        class="btn btn-primary chat-panel__send"
                        :disabled="!available || sending || !draft.trim()"
                    >
                        <span v-if="sending" class="spinner-border spinner-border-sm"></span>
                        <i v-else class="ri-send-plane-2-fill"></i>
                    </button>
                </div>
            </form>
        </template>
    </div>
</template>

<script setup>
import { nextTick, ref, watch } from 'vue';
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
    providerName: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['send']);

const { t } = useI18n();
const draft = ref('');
const messagesEl = ref(null);

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

function submit() {
    const message = draft.value.trim();

    if (! message || props.sending || ! props.available) {
        return;
    }

    emit('send', message);
    draft.value = '';
}
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
    white-space: pre-wrap;
    word-break: break-word;
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

.chat-panel__composer {
    flex: 0 0 auto;
}

.chat-panel__composer-inner {
    display: flex;
    align-items: flex-end;
    gap: 0.5rem;
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
