<template>
    <div class="chat-conversations d-flex flex-column">
        <div class="p-3 border-bottom">
            <button type="button" class="btn btn-primary w-100 btn-wave" :disabled="creating" @click="$emit('create')">
                <span v-if="creating" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="ri-add-line me-1 align-middle"></i>
                {{ t('ai_chat.new_chat') }}
            </button>
        </div>

        <div class="flex-fill overflow-auto chat-conversations__list">
            <div v-if="loading" class="p-3">
                <div v-for="n in 4" :key="n" class="placeholder-glow mb-3">
                    <span class="placeholder col-8 mb-1 d-block"></span>
                    <span class="placeholder col-11 d-block"></span>
                </div>
            </div>

            <div v-else-if="!conversations.length" class="text-center text-muted p-4">
                <i class="ri-chat-3-line fs-2 d-block mb-2"></i>
                <p class="fw-semibold mb-1 fs-13">{{ t('ai_chat.no_conversations') }}</p>
                <p class="fs-12 mb-0">{{ t('ai_chat.no_conversations_hint') }}</p>
            </div>

            <ul v-else class="list-unstyled mb-0">
                <li
                    v-for="conversation in conversations"
                    :key="conversation.id"
                    class="chat-conversation-item"
                    :class="{ 'chat-conversation-item--active': conversation.id === activeId }"
                >
                    <button type="button" class="chat-conversation-item__button" @click="$emit('select', conversation.id)">
                        <span class="d-block fw-semibold fs-13 text-truncate">
                            {{ conversation.title || t('ai_chat.untitled_conversation') }}
                        </span>
                        <span v-if="conversation.last_message_preview" class="d-block text-muted fs-12 text-truncate">
                            {{ conversation.last_message_preview }}
                        </span>
                    </button>
                    <button
                        type="button"
                        class="chat-conversation-item__delete"
                        :title="t('ai_chat.delete_conversation')"
                        @click="$emit('delete', conversation.id)"
                    >
                        <i class="ri-delete-bin-line"></i>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

defineProps({
    conversations: {
        type: Array,
        default: () => [],
    },
    activeId: {
        type: [Number, String],
        default: null,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    creating: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['select', 'create', 'delete']);

const { t } = useI18n();
</script>

<style scoped>
.chat-conversations {
    height: 100%;
    min-height: 0;
    border-inline-end: 1px solid var(--default-border, #e9edf1);
}

.chat-conversations__list {
    min-height: 0;
}

.chat-conversation-item {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding-inline-end: 0.5rem;
    border-bottom: 1px solid var(--default-border, #f1f3f5);
}

.chat-conversation-item--active {
    background-color: rgba(var(--primary-rgb, 55, 125, 255), 0.08);
}

.chat-conversation-item__button {
    flex: 1 1 auto;
    min-width: 0;
    text-align: start;
    background: none;
    border: 0;
    padding: 0.75rem 1rem;
    cursor: pointer;
}

.chat-conversation-item__delete {
    flex: 0 0 auto;
    background: none;
    border: 0;
    color: var(--text-muted, #8c9097);
    padding: 0.5rem;
    opacity: 0;
    transition: opacity 0.15s ease;
}

.chat-conversation-item:hover .chat-conversation-item__delete {
    opacity: 1;
}

.chat-conversation-item__delete:hover {
    color: var(--bs-danger, #dc3545);
}
</style>
