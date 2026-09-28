<template>
    <div>
        <WalletPageHeader :title="t('chat.reports.title')" :section="t('sidebar.chat')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm" role="group">
                    <button v-for="s in statusTabs" :key="s || 'all'" type="button" class="btn" :class="filters.status === s ? 'btn-primary' : 'btn-outline-light text-default'" @click="filters.status = s">
                        {{ s ? t(`chat.reports.status_${s}`) : t('wallet.common.all_statuses') }}
                        <span v-if="s === 'pending' && pendingCount" class="badge bg-danger ms-1">{{ pendingCount }}</span>
                    </button>
                </div>
                <select v-model="filters.report_type_id" class="form-select form-select-sm ms-auto" style="max-width: 220px;">
                    <option value="">{{ t('chat.reports.all_types') }}</option>
                    <option v-for="type in types" :key="type.id" :value="type.id">{{ type.name }}</option>
                </select>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">#</th>
                                <th>{{ t('chat.reports.reason') }}</th>
                                <th>{{ t('chat.reports.reporter') }}</th>
                                <th>{{ t('chat.reports.reported') }}</th>
                                <th>{{ t('chat.reports.conversation') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th>{{ t('wallet.common.date') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="8" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="8" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" role="button" @click="open(row)">
                                    <td class="ps-4 text-muted">{{ row.id }}</td>
                                    <td class="fw-semibold">{{ row.type?.name || '—' }}</td>
                                    <td><Person :person="row.reporter" /></td>
                                    <td>
                                        <template v-if="row.reported?.length"><Person v-for="p in row.reported" :key="`${p.type}:${p.id}`" :person="p" /></template>
                                        <span v-else class="text-muted fs-12">{{ t('chat.reports.whole_group') }}</span>
                                    </td>
                                    <td class="fs-12">
                                        <i :class="row.conversation?.type === 'group' ? 'ri-group-line' : 'ri-user-line'" class="me-1"></i>
                                        {{ row.conversation?.title || t(`chat.reports.type_${row.conversation?.type || 'direct'}`) }}
                                        <span class="text-muted">· {{ t('chat.reports.messages_count', { count: row.messages_count }) }}</span>
                                    </td>
                                    <td><span class="badge" :class="statusClass(row.status)">{{ t(`chat.reports.status_${row.status}`) }}</span></td>
                                    <td class="fs-12">{{ fmtDate(row.created_at) }}</td>
                                    <td class="text-end pe-4"><i class="ri-arrow-right-s-line text-muted rtl-flip"></i></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <WalletModal :show="!!current" :title="current ? `${t('chat.reports.report')} #${current.id}` : ''" size="xl" @close="current = null">
            <div v-if="current" class="row g-3">
                <div class="col-lg-5">
                    <dl class="row fs-13 mb-0">
                        <dt class="col-5 text-muted fw-normal">{{ t('chat.reports.reason') }}</dt><dd class="col-7 fw-semibold">{{ current.type?.name || '—' }}</dd>
                        <dt class="col-5 text-muted fw-normal">{{ t('chat.reports.reporter') }}</dt><dd class="col-7"><Person :person="current.reporter" /></dd>
                        <dt class="col-5 text-muted fw-normal">{{ t('chat.reports.reported') }}</dt>
                        <dd class="col-7">
                            <template v-if="current.reported?.length"><Person v-for="p in current.reported" :key="`${p.type}:${p.id}`" :person="p" /></template>
                            <span v-else class="text-muted">{{ t('chat.reports.whole_group') }}</span>
                        </dd>
                        <dt class="col-5 text-muted fw-normal">{{ t('chat.reports.conversation') }}</dt><dd class="col-7">{{ current.conversation?.title || t(`chat.reports.type_${current.conversation?.type || 'direct'}`) }}</dd>
                        <dt class="col-5 text-muted fw-normal">{{ t('wallet.common.date') }}</dt><dd class="col-7">{{ fmtDate(current.created_at) }}</dd>
                        <template v-if="current.reviewed_at">
                            <dt class="col-5 text-muted fw-normal">{{ t('chat.reports.reviewed_by') }}</dt><dd class="col-7">{{ current.reviewed_by }} · {{ fmtDate(current.reviewed_at) }}</dd>
                        </template>
                    </dl>
                    <div v-if="current.details" class="alert alert-light fs-13 mt-2 mb-0" style="white-space: pre-line;">{{ current.details }}</div>

                    <form v-if="canUpdate" id="report-review-form" class="mt-3" @submit.prevent="review">
                        <label class="form-label">{{ t('wallet.common.status') }}</label>
                        <select v-model="reviewForm.status" class="form-select mb-2">
                            <option v-for="s in statuses" :key="s" :value="s">{{ t(`chat.reports.status_${s}`) }}</option>
                        </select>
                        <label class="form-label">{{ t('chat.reports.admin_note') }}</label>
                        <textarea v-model="reviewForm.admin_note" rows="3" maxlength="2000" class="form-control"></textarea>
                    </form>
                    <div v-else-if="current.admin_note" class="mt-3 fs-13"><span class="text-muted">{{ t('chat.reports.admin_note') }}:</span> {{ current.admin_note }}</div>
                </div>

                <div class="col-lg-7">
                    <label class="form-label">{{ t('chat.reports.evidence') }}</label>
                    <div class="transcript rounded-3 p-3">
                        <div v-if="!current.messages?.length" class="text-center text-muted fs-13 py-4">{{ t('chat.reports.no_messages') }}</div>
                        <div v-for="m in current.messages" :key="m.id" class="d-flex mb-2" :class="m.is_reporter ? 'justify-content-end' : 'justify-content-start'">
                            <div class="bubble shadow-sm" :class="m.is_reporter ? 'mine' : ''">
                                <div v-if="!m.is_reporter" class="fs-11 fw-semibold text-primary">{{ m.sender?.name || '—' }}</div>
                                <div v-if="m.body" class="fs-13" style="white-space: pre-line;">{{ m.body }}</div>
                                <div v-else-if="m.type === 'system'" class="fs-12 text-muted fst-italic">{{ t('chat.reports.system_message') }}</div>
                                <div v-else-if="!m.attachments?.length" class="fs-12 text-muted fst-italic">{{ t('chat.reports.deleted_message') }}</div>
                                <div v-for="(url, i) in m.attachments" :key="i" class="mt-1">
                                    <img v-if="m.type === 'image' || m.type === 'sticker'" :src="url" class="rounded" style="max-width: 180px; max-height: 180px;">
                                    <a v-else :href="url" target="_blank" rel="noopener" class="fs-12"><i class="ri-attachment-2"></i> {{ t(`chat.reports.attachment_${m.type}`, m.type) }}</a>
                                </div>
                                <div class="fs-10 text-muted text-end mt-1">{{ fmtTime(m.sent_at) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="current = null">{{ t('close') }}</button>
                <button v-if="canUpdate" type="submit" form="report-review-form" class="btn btn-primary" :disabled="saving">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const canUpdate = computed(() => can('chat-reports.update'));
const statuses = ['pending', 'reviewing', 'resolved', 'dismissed'];
const statusTabs = ['', ...statuses];

const { rows, loading, pagination, filters, fetch } = useWalletList('chat-reports', { defaults: { status: 'pending', report_type_id: '' } });
const types = ref([]);
const pendingCount = ref(0);
const current = ref(null);
const saving = ref(false);
const reviewForm = reactive({ status: 'pending', admin_note: '' });

const Person = defineComponent({
    props: { person: { type: Object, default: null } },
    setup(props) {
        return () => (props.person
            ? h('div', { class: 'lh-sm mb-1' }, [
                h('div', { class: 'fw-semibold fs-13' }, props.person.name || `#${props.person.id}`),
                props.person.phone ? h('div', { class: 'fs-11 text-muted', dir: 'ltr' }, props.person.phone) : null,
            ])
            : h('span', { class: 'text-muted' }, '—'));
    },
});

function statusClass(status) {
    return {
        pending: 'bg-warning-transparent',
        reviewing: 'bg-info-transparent',
        resolved: 'bg-success-transparent',
        dismissed: 'bg-secondary-transparent',
    }[status] ?? 'bg-light';
}

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
const fmtTime = (iso) => (iso ? new Date(iso).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }) : '');

async function open(row) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/chat-reports/${row.id}`);

        current.value = data.data;
        Object.assign(reviewForm, { status: data.data.status, admin_note: data.data.admin_note || '' });
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function review() {
    saving.value = true;

    try {
        const { data } = await adminAxios.put(`/api/admin/v1/chat-reports/${current.value.id}`, reviewForm);

        current.value = data.data;
        showSuccess(t('chat.reports.saved'));
        fetch();
        loadPending();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        saving.value = false;
    }
}

async function loadPending() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-reports', { params: { per_page: 1 } });

        pendingCount.value = data.pending_count ?? data.meta?.pending_count ?? 0;
    } catch {
        pendingCount.value = 0;
    }
}

onMounted(async () => {
    fetch(1);
    loadPending();

    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-report-types/dropdown');

        types.value = data.data ?? [];
    } catch {
        types.value = [];
    }
});
</script>

<style scoped>
.transcript { background: #efeae2; max-height: 460px; overflow-y: auto; }
.bubble { background: #fff; border-radius: 12px; padding: 6px 10px; max-width: 78%; }
.bubble.mine { background: #d9fdd3; }
[data-theme-mode='dark'] .transcript { background: #0b141a; }
[data-theme-mode='dark'] .bubble { background: #202c33; color: #e9edef; }
[data-theme-mode='dark'] .bubble.mine { background: #005c4b; }
</style>
