<template>
    <div>
        <WalletPageHeader :title="t('support.title')" :section="t('sidebar.users')" :total="pagination?.total ?? null" />

        <div class="row g-3 mb-3">
            <div v-for="card in statusCards" :key="card.value" class="col-6 col-xl-3">
                <button
                    type="button"
                    class="card custom-card support-stat w-100 text-start mb-0"
                    :class="{ 'support-stat--active': filters.status === card.value }"
                    @click="filters.status = filters.status === card.value ? '' : card.value"
                >
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="avatar avatar-md avatar-rounded" :class="`bg-${card.tone}-transparent`">
                            <i :class="[card.icon, `text-${card.tone}`]"></i>
                        </span>
                        <div>
                            <div class="text-muted fs-12">{{ t(`support.status.${card.value}`) }}</div>
                            <div class="fw-semibold fs-18">{{ counts[card.value] ?? '—' }}</div>
                        </div>
                    </div>
                </button>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="filters.search" type="search" class="form-control" :placeholder="t('support.search')">
                        <button v-if="filters.search" type="button" class="btn btn-light border" @click="filters.search = ''">
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                    <Select
                        v-model="filters.status"
                        filter
                        :filter-placeholder="t('search_placeholder')"
                        :options="statusOptions"
                        option-label="label"
                        option-value="value"
                        class="wallet-filter-select"
                    />
                    <div class="form-check form-switch mb-0 ms-1">
                        <input id="support-mine" v-model="mineOnly" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label fs-13" for="support-mine">{{ t('support.mine_only') }}</label>
                    </div>
                </div>
                <div class="catalog-toolbar-actions d-flex align-items-center gap-2">
                    <span class="badge bg-success-transparent d-inline-flex align-items-center gap-1" :title="t('support.live_hint')">
                        <span class="support-live-dot"></span>{{ t('support.live') }}
                    </span>
                    <button type="button" class="btn btn-sm btn-light" :title="t('support.refresh')" @click="reload">
                        <i class="ri-refresh-line"></i>
                    </button>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">#</th>
                                <th>{{ t('support.customer') }}</th>
                                <th>{{ t('support.subject') }}</th>
                                <th>{{ t('support.last_message') }}</th>
                                <th>{{ t('support.status_label') }}</th>
                                <th>{{ t('support.agent') }}</th>
                                <th>{{ t('support.updated') }}</th>
                                <th class="text-end pe-4">{{ t('support.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading && !rows.length" :rows="8" :columns="8" />
                            <tr v-else-if="!rows.length">
                                <td colspan="8" class="border-0">
                                    <div class="text-center py-5">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                            <i class="ri-customer-service-2-line fs-2 text-primary"></i>
                                        </span>
                                        <p class="fw-semibold mb-1">{{ t('support.empty_title') }}</p>
                                        <p class="text-muted mb-0">{{ t('support.empty') }}</p>
                                    </div>
                                </td>
                            </tr>
                            <template v-else>
                                <tr
                                    v-for="row in rows"
                                    :key="row.id"
                                    class="crm-contact support-row"
                                    :class="{ 'support-row--flash': flashing[row.id] }"
                                    @click="open(row)"
                                >
                                    <td class="ps-4"><span class="fw-semibold">#{{ row.id }}</span></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent"><i class="ri-user-line text-primary"></i></span>
                                            <div>
                                                <span class="fw-semibold d-block">{{ row.user?.name || row.user?.phone || `#${row.user?.id}` }}</span>
                                                <span v-if="row.user?.name" class="d-block text-muted fs-11" dir="ltr">{{ row.user?.phone }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="support-cell-title">{{ row.title }}</td>
                                    <td class="support-cell-preview text-muted">
                                        <i v-if="row.last_message" :class="row.last_message.sender === 'support' ? 'ri-customer-service-2-line' : 'ri-user-line'" class="me-1"></i>
                                        <span v-if="row.last_message?.body">{{ row.last_message.body }}</span>
                                        <span v-else-if="row.last_message?.has_image"><i class="ri-image-line me-1"></i>{{ t('support.photo') }}</span>
                                    </td>
                                    <td><span class="badge" :class="statusClass(row.status)">{{ t(`support.status.${row.status}`) }}</span></td>
                                    <td>
                                        <span v-if="row.admin" class="badge bg-secondary-transparent">{{ row.admin.name }}</span>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td class="text-muted fs-12">{{ formatDateTime(row.last_message_at, locale) }}</td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('support.open')" @click.stop="open(row)">
                                            <i class="ri-chat-3-line"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <ConversationModal
            :show="showConversation"
            :ticket="selected"
            @close="showConversation = false"
            @ticket-changed="onTicketChanged"
        />
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import WalletPageHeader from '../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../components/wallet/WalletPagination.vue';
import useSupportRealtime from '../../../../../../composables/useSupportRealtime';
import useToast from '../../../../../../composables/useToast';
import useWalletList from '../../../../../../composables/useWalletList';
import { formatDateTime } from '../../../../../../utils/walletMoney';
import ConversationModal from './ConversationModal.vue';
import { statusClass } from './supportStatus';

const { t, locale } = useI18n();
const { showSuccess } = useToast();

const statuses = ['opened', 'reopened', 'resolved', 'closed'];
const statusCards = [
    { value: 'opened', icon: 'ri-inbox-line', tone: 'primary' },
    { value: 'reopened', icon: 'ri-refresh-line', tone: 'info' },
    { value: 'resolved', icon: 'ri-check-double-line', tone: 'success' },
    { value: 'closed', icon: 'ri-lock-line', tone: 'danger' },
];

const mineOnly = ref(false);
const { rows, loading, pagination, filters, fetch } = useWalletList('support-tickets', {
    defaults: { search: '', status: '', mine: '' },
});

const statusOptions = computed(() => [
    { value: '', label: t('support.all_statuses') },
    ...statuses.map((status) => ({ value: status, label: t(`support.status.${status}`) })),
]);

const counts = reactive({});
const flashing = reactive({});
const showConversation = ref(false);
const selected = ref(null);

watch(mineOnly, (value) => {
    filters.mine = value ? 1 : '';
});

async function loadCounts() {
    await Promise.all(statuses.map(async (status) => {
        try {
            const { data } = await adminAxios.get('/api/admin/v1/support-tickets', { params: { status, per_page: 1 } });

            counts[status] = data.pagination?.total ?? 0;
        } catch {
            counts[status] = counts[status] ?? 0;
        }
    }));
}

async function reload() {
    await Promise.all([fetch(1), loadCounts()]);
}

function open(row) {
    selected.value = row;
    showConversation.value = true;
}

function flash(id) {
    flashing[id] = true;
    setTimeout(() => { flashing[id] = false; }, 2200);
}

/** Put a ticket that changed (here or from the live feed) into the list, newest activity first. */
function upsert(ticket) {
    const matchesFilter = ! filters.status || filters.status === ticket.status;
    const index = rows.value.findIndex((row) => row.id === ticket.id);

    if (index >= 0) {
        rows.value.splice(index, 1);
    }

    if (matchesFilter && (index >= 0 || pagination.value?.current_page === 1)) {
        rows.value.unshift(ticket);
        flash(ticket.id);
    }

    if (selected.value?.id === ticket.id) {
        selected.value = ticket;
    }
}

function onTicketChanged(ticket) {
    upsert(ticket);
    loadCounts();
}

useSupportRealtime((name, payload) => {
    if (! payload?.ticket) {
        return;
    }

    upsert(payload.ticket);
    loadCounts();

    if (name === 'support.ticket.created') {
        showSuccess(t('support.new_ticket_toast', { id: payload.ticket.id }));
    }
});

onMounted(reload);
</script>

<style scoped>
.support-stat {
    cursor: pointer;
    border: 1px solid transparent;
    transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}

.support-stat:hover {
    transform: translateY(-2px);
}

.support-stat--active {
    border-color: rgba(var(--primary-rgb, 132, 90, 223), 0.6);
    box-shadow: 0 6px 18px rgba(var(--primary-rgb, 132, 90, 223), 0.15);
}

.support-row {
    cursor: pointer;
}

.support-row--flash {
    animation: support-flash 2.2s ease;
}

@keyframes support-flash {
    0% { background-color: rgba(var(--primary-rgb, 132, 90, 223), 0.22); }
    100% { background-color: transparent; }
}

.support-cell-title {
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.support-cell-preview {
    max-width: 260px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.support-live-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #22c55e;
    animation: support-pulse 1.6s infinite;
}

@keyframes support-pulse {
    0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.6); }
    70% { box-shadow: 0 0 0 7px rgba(34, 197, 94, 0); }
    100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}
</style>
