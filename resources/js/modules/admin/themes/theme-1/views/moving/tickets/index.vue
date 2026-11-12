<template>
    <div>
        <WalletPageHeader :title="t('moving.tickets.title')" :section="t('sidebar.moving')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm" role="group">
                    <button v-for="s in ['escalated', 'awaiting_customer', 'resolved', 'unresolved']" :key="s" type="button" class="btn" :class="filters.status === s ? 'btn-primary' : 'btn-outline-primary'" @click="filters.status = s">
                        {{ t(`moving.tickets.status.${s}`) }}
                    </button>
                </div>
                <Select
                    v-model="filters.type"
                    :options="typeOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('moving.tickets.all_types')"
                    show-clear
                    class="wallet-filter-select ms-auto"
                />
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('moving.tickets.subject') }}</th>
                                <th>{{ t('moving.tickets.type_label') }}</th>
                                <th>{{ t('moving.tickets.escalation_reason') }}</th>
                                <th>{{ t('moving.cases.opened_at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :layout="['text', 'badge', 'two-lines', 'text']" />
                            <tr v-else-if="!rows.length">
                                <td colspan="4" class="text-center text-muted py-5"><i class="ri-customer-service-2-line fs-24 d-block mb-1"></i>{{ t('wallet.common.empty') }}</td>
                            </tr>
                            <tr v-for="row in rows" v-else :key="row.uuid" role="button" @click="open(row)">
                                <td class="ps-4 fw-semibold">{{ row.subject }}</td>
                                <td><span class="badge bg-light text-dark">{{ t(`moving.tickets.type.${row.type}`) }}</span></td>
                                <td class="text-wrap fs-13" style="min-width: 220px">{{ row.escalation_reason || '—' }}</td>
                                <td class="fs-12">{{ formatDateTime(row.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <WalletModal :show="!!ticket" :title="ticket?.subject ?? ''" size="lg" @close="ticket = null">
            <template v-if="ticket">
                <div class="d-flex flex-column gap-2 mb-3" style="max-height: 320px; overflow-y: auto">
                    <div v-if="!ticket.messages?.length" class="text-center text-muted fs-13 py-3">{{ t('wallet.common.empty') }}</div>
                    <div v-for="(m, i) in ticket.messages" :key="i" class="rounded-3 p-2 fs-13" :class="m.author === 'user' ? 'bg-light' : (m.author === 'admin' ? 'bg-primary-transparent' : 'bg-info-transparent')">
                        <div class="fs-11 text-muted mb-1">{{ t(`moving.tickets.author.${m.author}`) }} · {{ formatDateTime(m.at) }}</div>
                        {{ m.body }}
                    </div>
                </div>
                <form v-if="canAct" id="moving-ticket-form" @submit.prevent="act">
                    <div class="mb-3">
                        <label class="form-label" for="moving-ticket-body">{{ t('moving.tickets.reply') }}</label>
                        <textarea id="moving-ticket-body" v-model="form.body" rows="2" maxlength="3000" class="form-control" :class="{ 'is-invalid': errorOf('body') }" @input="clearError('body')"></textarea>
                        <div v-if="errorOf('body')" class="invalid-feedback d-block">{{ errorOf('body') }}</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="moving-ticket-close">{{ t('moving.tickets.close_as') }}</label>
                            <select id="moving-ticket-close" v-model="form.close" class="form-select" :class="{ 'is-invalid': errorOf('close') }" @change="clearError('close')">
                                <option :value="null">{{ t('moving.tickets.keep_open') }}</option>
                                <option value="resolved">{{ t('moving.tickets.status.resolved') }}</option>
                                <option value="unresolved">{{ t('moving.tickets.status.unresolved') }}</option>
                            </select>
                            <div v-if="errorOf('close')" class="invalid-feedback d-block">{{ errorOf('close') }}</div>
                        </div>
                        <div v-if="form.close" class="col-md-8">
                            <label class="form-label" for="moving-ticket-reason">{{ t('moving.tickets.closing_reason') }} <span class="text-danger">*</span></label>
                            <input id="moving-ticket-reason" v-model="form.reason" type="text" maxlength="500" class="form-control" :class="{ 'is-invalid': errorOf('reason') }" required @input="clearError('reason')">
                            <div v-if="errorOf('reason')" class="invalid-feedback d-block">{{ errorOf('reason') }}</div>
                        </div>
                    </div>
                </form>
                <div v-else-if="['resolved', 'unresolved'].includes(ticket.status)" class="alert alert-light border mb-0 fs-13">{{ t(`moving.tickets.status.${ticket.status}`) }} · {{ ticket.closing_reason || '—' }}</div>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="ticket = null">{{ canAct ? t('cancel') : t('close') }}</button>
                <button v-if="canAct" type="submit" form="moving-ticket-form" class="btn btn-primary" :disabled="busy">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import { formatDateTime as walletDateTime } from '../../../../../../../utils/walletMoney';

// Moving tickets taken to Dorr (docs/moving_tasks.md B-15): answered and closed with a reason (REQ-063, REQ-064).
const { t, locale } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();

const types = ['suggestion', 'inquiry', 'complaint', 'safety', 'inventory', 'delay', 'financial', 'note'];
const typeOptions = computed(() => types.map((type) => ({ value: type, label: t(`moving.tickets.type.${type}`) })));
const { rows, loading, pagination, filters, fetch } = useWalletList('moving-tickets', { defaults: { status: 'escalated', type: '' }, perPage: 20 });
const busy = ref(false);
const ticket = ref(null);
const form = reactive({ body: '', close: null, reason: '' });
const errors = reactive({});

const canAct = computed(() => !!ticket.value && !['resolved', 'unresolved'].includes(ticket.value.status) && can('moving-tickets.update'));
const formatDateTime = (iso) => (iso ? walletDateTime(iso, locale.value) : '—');
const errorOf = (key) => errors[key]?.[0] ?? null;
const clearError = (key) => delete errors[key];
const resetForm = () => {
    Object.assign(form, { body: '', close: null, reason: '' });
    Object.keys(errors).forEach((key) => delete errors[key]);
};

async function open(row) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/moving-tickets/${row.uuid}`);
        ticket.value = data.data;
        resetForm();
    } catch (e) {
        showError(extractApiErrorMessage(e));
    }
}

async function act() {
    busy.value = true;
    Object.keys(errors).forEach((key) => delete errors[key]);
    try {
        const { data } = await adminAxios.post(`/api/admin/v1/moving-tickets/${ticket.value.uuid}/act`, form);
        ticket.value = data.data;
        showSuccess(t('moving.common.saved'));
        resetForm();
        fetch(pagination.value?.current_page ?? 1);
    } catch (e) {
        const bag = e?.response?.data?.errors ?? {};
        Object.assign(errors, bag);
        if (!Object.keys(bag).length) {
            showError(extractApiErrorMessage(e));
        }
    } finally {
        busy.value = false;
    }
}

onMounted(() => fetch(1));
</script>
