<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_chat.title') }}</p>
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
                            :provider-name="status.provider_name"
                            @send="sendMessage"
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
import userAxios from '../../../../api/userAxios';
import ChatPanel from '../../../../components/chat/ChatPanel.vue';
import ConversationSidebar from '../../../../components/chat/ConversationSidebar.vue';
import ConfirmDeleteModal from '../../../../components/ui/ConfirmDeleteModal.vue';
import { useConfirmDelete } from '../../../../composables/useConfirmDelete';
import useToast, { extractApiErrorMessage } from '../../../../composables/useToast';

const { t } = useI18n();
const { showError } = useToast();
const deleteConfirm = useConfirmDelete();

const status = reactive({ available: true, provider_key: null, provider_name: null });
const conversations = ref([]);
const activeConversationId = ref(null);
const activeMessages = ref([]);

const loadingConversations = ref(true);
const creatingConversation = ref(false);
const sending = ref(false);

async function loadStatus() {
    try {
        const { data } = await userAxios.get('/api/user/v1/ai-chat/status');
        Object.assign(status, data.data ?? {});
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
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

async function sendMessage(message) {
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
    });

    sending.value = true;

    try {
        const { data } = await userAxios.post(
            `/api/user/v1/ai-chat/conversations/${activeConversationId.value}/messages`,
            { message },
        );

        activeMessages.value = data.data.conversation.messages;
        await loadConversations(false);
    } catch (error) {
        activeMessages.value.pop();
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        sending.value = false;
    }
}

onMounted(async () => {
    await Promise.all([loadStatus(), loadConversations()]);
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
