<template>
    <WalletModal :show="show" :title="ticket ? `#${ticket.number} · ${ticket.title}` : ''" size="xl" @close="emit('close')">
        <div v-if="ticket" class="support-chat">
            <div class="support-chat__head">
                <div class="d-flex align-items-center gap-3 min-w-0">
                    <span class="avatar avatar-lg avatar-rounded bg-primary-transparent"><i class="ri-user-line text-primary fs-5"></i></span>
                    <div class="min-w-0">
                        <div class="fw-semibold text-truncate">{{ ticket.user?.name || ticket.user?.phone }}</div>
                        <div v-if="ticket.user?.name" class="text-muted fs-12" dir="ltr">{{ ticket.user?.phone }}</div>
                        <div v-if="ticket.admin" class="text-muted fs-12">
                            <i class="ri-customer-service-2-line me-1"></i>{{ t('support.assigned_to', { name: ticket.admin.name }) }}
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center flex-wrap gap-2 ms-auto">
                    <span class="badge fs-12" :class="statusClass(ticket.status)">{{ t(`support.status.${ticket.status}`) }}</span>
                    <span v-if="ticket.auto_reply_stopped" class="badge bg-warning-transparent fs-12" :title="t('support.wants_agent_hint')">
                        <i class="ri-user-voice-line me-1"></i>{{ t('support.wants_agent') }}
                    </span>
                    <template v-if="canChangeStatus">
                        <Select
                            v-model="nextStatus"
                            :options="statusOptions"
                            option-label="label"
                            option-value="value"
                            append-to="self"
                            class="support-status-select"
                            :disabled="movingStatus"
                        />
                        <button
                            type="button"
                            class="btn btn-sm btn-primary"
                            :disabled="movingStatus || nextStatus === ticket.status"
                            @click="applyStatus"
                        >
                            <span v-if="movingStatus" class="spinner-border spinner-border-sm"></span>
                            <span v-else>{{ t('support.apply') }}</span>
                        </button>
                    </template>
                    <button type="button" class="btn btn-sm btn-light" :title="t('support.history')" @click="toggleHistory">
                        <i class="ri-history-line"></i>
                    </button>
                </div>
            </div>

            <div v-if="showHistory" class="support-history">
                <div v-for="a in activities" :key="a.id" class="support-history__item">
                    <span class="badge" :class="statusClass(a.status)">{{ t(`support.status.${a.status}`) }}</span>
                    <span class="text-muted fs-12">
                        {{ a.actor === 'support' ? (a.admin || t('support.support_team')) : t('support.the_customer') }}
                        · {{ formatDateTime(a.created_at, locale) }}
                    </span>
                </div>
            </div>

            <div ref="scroller" class="support-chat__body">
                <div v-if="loading" class="text-center py-5"><span class="spinner-border"></span></div>
                <template v-else>
                    <div v-if="hasEarlier" class="text-center mb-3">
                        <button type="button" class="btn btn-sm btn-light" :disabled="loadingEarlier" @click="loadEarlier">
                            <span v-if="loadingEarlier" class="spinner-border spinner-border-sm me-1"></span>{{ t('support.load_earlier') }}
                        </button>
                    </div>
                    <template v-for="group in grouped" :key="group.day">
                        <div class="support-day"><span>{{ group.label }}</span></div>
                        <div
                            v-for="message in group.items"
                            :key="message.id"
                            class="support-msg"
                            :class="message.sender === 'user' ? 'support-msg--customer' : 'support-msg--agent'"
                        >
                            <div class="support-msg__bubble" :class="{ 'support-msg__bubble--auto': message.is_auto }">
                                <div v-if="message.is_auto" class="support-msg__who support-msg__who--auto">
                                    <i class="ri-robot-2-line me-1"></i>{{ t('support.auto_reply') }} · {{ t(`support.auto_kind.${message.auto_kind}`) }}
                                </div>
                                <div v-else-if="message.sender === 'support'" class="support-msg__who">{{ message.agent_name || t('support.support_team') }}</div>
                                <a v-if="message.image_url" :href="message.image_url" target="_blank" rel="noopener" class="support-msg__image">
                                    <img :src="message.image_url" alt="">
                                </a>
                                <p v-if="message.body" class="mb-0">{{ message.body }}</p>
                                <div class="support-msg__time">{{ timeOf(message.created_at) }}</div>
                            </div>
                        </div>
                    </template>
                    <div v-if="!messages.length" class="text-center text-muted py-5">{{ t('support.no_messages') }}</div>
                </template>
            </div>

            <div class="support-chat__composer">
                <div v-if="!canReply" class="alert alert-light mb-0 text-center">{{ t('support.no_reply_permission') }}</div>
                <div v-else-if="!ticket.accepts_replies" class="alert alert-warning mb-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span><i class="ri-lock-line me-1"></i>{{ t('support.closed_notice') }}</span>
                    <button v-if="canChangeStatus" type="button" class="btn btn-sm btn-warning" :disabled="movingStatus" @click="reopen">
                        {{ t('support.reopen') }}
                    </button>
                </div>
                <form v-else class="d-flex align-items-end gap-2" @submit.prevent="send">
                    <label class="btn btn-light btn-icon mb-0" :title="t('support.attach')">
                        <i class="ri-image-add-line"></i>
                        <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp" class="d-none" @change="pick">
                    </label>
                    <div class="flex-fill position-relative">
                        <div v-if="suggestions.length" class="support-quick" role="listbox">
                            <button
                                v-for="reply in suggestions"
                                :key="reply.id"
                                type="button"
                                class="support-quick__item"
                                @mousedown.prevent="useQuickReply(reply)"
                            >
                                <span class="support-quick__shortcut" dir="ltr">/{{ reply.shortcut }}</span>
                                <span class="support-quick__text"><strong>{{ reply.title }}</strong><small>{{ reply.body }}</small></span>
                            </button>
                        </div>
                        <div v-if="previewUrl" class="support-preview">
                            <img :src="previewUrl" alt="">
                            <button type="button" class="btn-close" @click="clearImage"></button>
                        </div>
                        <textarea
                            v-model="draft"
                            rows="1"
                            class="form-control"
                            :placeholder="t('support.type_reply')"
                            maxlength="4000"
                            @keydown.enter.exact.prevent="onEnter"
                            @keydown.tab="onTab"
                            @keydown.esc="draft = ''"
                        ></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-icon" :disabled="sending || (!draft.trim() && !image)">
                        <span v-if="sending" class="spinner-border spinner-border-sm"></span>
                        <i v-else class="ri-send-plane-2-fill"></i>
                    </button>
                </form>
                <div v-if="errorMessage" class="text-danger fs-12 mt-2">{{ errorMessage }}</div>
            </div>
        </div>
    </WalletModal>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import WalletModal from '../../../../../../components/wallet/WalletModal.vue';
import { usePermission } from '../../../../../../composables/usePermission';
import useSupportRealtime from '../../../../../../composables/useSupportRealtime';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';
import { formatDateTime } from '../../../../../../utils/walletMoney';
import { statusClass, SUPPORT_STATUSES } from './supportStatus';

const props = defineProps({
    show: { type: Boolean, default: false },
    /** The ticket row from the list (id, title, user, status...). */
    ticket: { type: Object, default: null },
});

const emit = defineEmits(['close', 'ticket-changed']);

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess } = useToast();

const canReply = computed(() => can('support-tickets.reply'));
const canChangeStatus = computed(() => can('support-tickets.change-status'));

const messages = ref([]);
const loading = ref(false);
const loadingEarlier = ref(false);
const earlierPage = ref(0);
const activities = ref([]);
const showHistory = ref(false);
const draft = ref('');
const quickReplies = ref(null);
const image = ref(null);
const previewUrl = ref('');
const sending = ref(false);
const errorMessage = ref('');
const nextStatus = ref('opened');
const movingStatus = ref(false);
const scroller = ref(null);
const fileInput = ref(null);

const hasEarlier = ref(false);

const statusOptions = computed(() => SUPPORT_STATUSES.map((status) => ({ value: status, label: t(`support.status.${status}`) })));

const grouped = computed(() => {
    const groups = [];

    messages.value.forEach((message) => {
        const day = new Date(message.created_at).toDateString();
        const last = groups[groups.length - 1];

        if (last && last.day === day) {
            last.items.push(message);
        } else {
            groups.push({
                day,
                label: new Date(message.created_at).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long' }),
                items: [message],
            });
        }
    });

    return groups;
});

function timeOf(value) {
    return new Date(value).toLocaleTimeString(locale.value === 'ar' ? 'ar-EG' : 'en-US', { hour: '2-digit', minute: '2-digit' });
}

async function scrollToEnd(smooth = false) {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
}

/** The newest page of the conversation first; older ones come with "load earlier". */
async function load() {
    if (! props.ticket) {
        return;
    }

    loading.value = true;
    messages.value = [];
    earlierPage.value = 0;
    hasEarlier.value = false;
    errorMessage.value = '';
    showHistory.value = false;
    nextStatus.value = props.ticket.status;

    try {
        const { data } = await adminAxios.get(`/api/admin/v1/support-tickets/${props.ticket.id}/messages`, { params: { per_page: 50, page: 1, order: 'desc' } });

        messages.value = (data.data ?? []).slice().reverse();
        earlierPage.value = 1;
        hasEarlier.value = Boolean(data.pagination?.has_more_pages);
    } catch (error) {
        errorMessage.value = extractApiErrorMessage(error);
    } finally {
        loading.value = false;
        scrollToEnd();
    }
}

async function loadEarlier() {
    loadingEarlier.value = true;

    try {
        const { data } = await adminAxios.get(`/api/admin/v1/support-tickets/${props.ticket.id}/messages`, { params: { per_page: 50, page: earlierPage.value + 1, order: 'desc' } });

        messages.value = [...(data.data ?? []).slice().reverse(), ...messages.value];
        earlierPage.value += 1;
        hasEarlier.value = Boolean(data.pagination?.has_more_pages);
    } catch (error) {
        errorMessage.value = extractApiErrorMessage(error);
    } finally {
        loadingEarlier.value = false;
    }
}

async function toggleHistory() {
    showHistory.value = ! showHistory.value;

    if (showHistory.value) {
        try {
            const { data } = await adminAxios.get(`/api/admin/v1/support-tickets/${props.ticket.id}/activities`);

            activities.value = data.data ?? [];
        } catch {
            activities.value = [];
        }
    }
}

function pick(event) {
    const file = event.target.files?.[0];

    if (! file) {
        return;
    }

    image.value = file;
    previewUrl.value = URL.createObjectURL(file);
}

function clearImage() {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }

    image.value = null;
    previewUrl.value = '';

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

/** The active quick replies, loaded once (the "/" menu of the reply box). */
async function loadQuickReplies() {
    if (quickReplies.value !== null || ! canReply.value) {
        return;
    }

    try {
        const { data } = await adminAxios.get('/api/admin/v1/support-tickets/quick-replies');

        quickReplies.value = data.data ?? [];
    } catch {
        quickReplies.value = [];
    }
}

// "/" at the start of the box (and no space yet) opens the menu; typing narrows it by shortcut or title.
const suggestions = computed(() => {
    if (! quickReplies.value?.length || ! draft.value.startsWith('/') || /\s/.test(draft.value)) {
        return [];
    }

    const needle = draft.value.slice(1).toLowerCase();

    return quickReplies.value
        .filter((reply) => ! needle || reply.shortcut.includes(needle) || reply.title.toLowerCase().includes(needle))
        .slice(0, 6);
});

function useQuickReply(reply) {
    // The agent can still edit it before sending: it goes out in their own name.
    draft.value = reply.body;
}

function onEnter() {
    if (suggestions.value.length) {
        useQuickReply(suggestions.value[0]);

        return;
    }

    send();
}

function onTab(event) {
    if (suggestions.value.length) {
        event.preventDefault();
        useQuickReply(suggestions.value[0]);
    }
}

async function send() {
    if (sending.value || (! draft.value.trim() && ! image.value)) {
        return;
    }

    sending.value = true;
    errorMessage.value = '';

    try {
        const form = new FormData();

        if (draft.value.trim()) {
            form.append('body', draft.value.trim());
        }

        if (image.value) {
            form.append('image', image.value);
        }

        const { data } = await adminAxios.post(`/api/admin/v1/support-tickets/${props.ticket.id}/messages`, form);

        addMessage(data.data);
        draft.value = '';
        clearImage();
        // Answering takes the ticket: the list learns who has it from the live event.
    } catch (error) {
        errorMessage.value = extractApiErrorMessage(error);
    } finally {
        sending.value = false;
    }
}

function addMessage(message) {
    if (! message || messages.value.some((m) => m.id === message.id)) {
        return;
    }

    messages.value.push(message);
    scrollToEnd(true);
}

async function setStatus(status) {
    movingStatus.value = true;
    errorMessage.value = '';

    try {
        const { data } = await adminAxios.patch(`/api/admin/v1/support-tickets/${props.ticket.id}/status`, { status });

        emit('ticket-changed', data.data);
        showSuccess(t('support.status_updated'));

        if (showHistory.value) {
            showHistory.value = false;
            toggleHistory();
        }
    } catch (error) {
        errorMessage.value = extractApiErrorMessage(error);
    } finally {
        movingStatus.value = false;
    }
}

const applyStatus = () => setStatus(nextStatus.value);
const reopen = () => setStatus('reopened');

// Live: a customer message (or a change from another agent) lands in the open conversation at once.
useSupportRealtime((name, payload) => {
    if (! props.show || ! props.ticket || payload?.ticket?.id !== props.ticket.id) {
        return;
    }

    if (name === 'support.message') {
        addMessage(payload.message);
    }
});

watch(() => props.ticket?.status, (status) => {
    if (status) {
        nextStatus.value = status;
    }
});

watch(() => [props.show, props.ticket?.id], ([visible]) => {
    if (visible) {
        draft.value = '';
        clearImage();
        load();
        loadQuickReplies();
    }
});
</script>

<style scoped>
.support-chat {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.support-chat__head {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
    border: 1px solid rgba(var(--primary-rgb, 132, 90, 223), 0.18);
    border-radius: 0.875rem;
    background: linear-gradient(135deg, rgba(var(--primary-rgb, 132, 90, 223), 0.1), rgba(var(--primary-rgb, 132, 90, 223), 0.02));
}

.support-status-select {
    min-width: 150px;
}

.support-history {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    padding: 0.75rem 1rem;
    border-radius: 0.75rem;
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.05);
}

.support-history__item {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.support-chat__body {
    height: 46vh;
    min-height: 260px;
    overflow-y: auto;
    padding: 1rem;
    border-radius: 0.875rem;
    background: var(--default-body-bg-color, rgba(0, 0, 0, 0.03));
}

.support-day {
    display: flex;
    justify-content: center;
    margin: 0.75rem 0;
}

.support-day span {
    padding: 0.2rem 0.75rem;
    border-radius: 999px;
    font-size: 0.72rem;
    color: var(--text-muted, #8c9097);
    background: rgba(0, 0, 0, 0.06);
}

.support-msg {
    display: flex;
    margin-bottom: 0.55rem;
    animation: support-in 0.22s ease;
}

.support-msg--agent {
    justify-content: flex-end;
}

.support-msg__bubble {
    max-width: min(520px, 80%);
    padding: 0.55rem 0.8rem;
    border-radius: 1rem;
    word-break: break-word;
    white-space: pre-wrap;
}

.support-msg--customer .support-msg__bubble {
    border-bottom-left-radius: 0.25rem;
    background: var(--custom-white, #fff);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.support-msg--agent .support-msg__bubble {
    border-bottom-right-radius: 0.25rem;
    color: #fff;
    background: rgb(var(--primary-rgb, 132, 90, 223));
}

.support-msg--agent .support-msg__bubble--auto {
    color: inherit;
    border: 1px dashed rgba(var(--primary-rgb, 132, 90, 223), 0.45);
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.08);
}

.support-msg__who--auto {
    color: rgb(var(--primary-rgb, 132, 90, 223));
    opacity: 1;
}

.support-quick {
    position: absolute;
    inset-inline: 0;
    bottom: calc(100% + 6px);
    z-index: 5;
    display: flex;
    flex-direction: column;
    max-height: 240px;
    overflow-y: auto;
    border: 1px solid var(--default-border, #dee2e6);
    border-radius: 0.75rem;
    background: var(--custom-white, #fff);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}

.support-quick__item {
    display: flex;
    align-items: baseline;
    gap: 0.75rem;
    padding: 0.55rem 0.85rem;
    border: 0;
    background: transparent;
    text-align: start;
}

.support-quick__item:hover {
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.08);
}

.support-quick__shortcut {
    flex-shrink: 0;
    font-size: 0.78rem;
    font-weight: 600;
    color: rgb(var(--primary-rgb, 132, 90, 223));
}

.support-quick__text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.support-quick__text small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--text-muted, #8c9097);
}

.support-msg__who {
    margin-bottom: 0.15rem;
    font-size: 0.72rem;
    font-weight: 600;
    opacity: 0.85;
}

.support-msg__time {
    margin-top: 0.2rem;
    font-size: 0.66rem;
    text-align: end;
    opacity: 0.65;
}

.support-msg__image img {
    display: block;
    max-width: 260px;
    max-height: 240px;
    margin-bottom: 0.35rem;
    border-radius: 0.6rem;
    object-fit: cover;
}

.support-preview {
    position: relative;
    display: inline-block;
    margin-bottom: 0.4rem;
}

.support-preview img {
    max-height: 90px;
    border-radius: 0.5rem;
}

.support-preview .btn-close {
    position: absolute;
    top: 4px;
    inset-inline-end: 4px;
    background-color: rgba(255, 255, 255, 0.85);
    border-radius: 50%;
    padding: 0.3rem;
}

@keyframes support-in {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
