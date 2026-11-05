<template>
    <div>
        <WalletPageHeader :title="t('moving.tickets.title')" :section="t('sidebar.moving')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center py-3">
                <ul class="nav nav-tabs border-0">
                    <li v-for="s in ['escalated', 'awaiting_customer', 'resolved', 'unresolved']" :key="s" class="nav-item">
                        <a href="#" class="nav-link" :class="{ active: filters.status === s }" @click.prevent="filters.status = s; load(1)">{{ t(`moving.tickets.status.${s}`) }}</a>
                    </li>
                </ul>
                <select v-model="filters.type" class="form-select form-select-sm ms-auto" style="max-width: 180px" @change="load(1)">
                    <option value="">{{ t('moving.tickets.all_types') }}</option>
                    <option v-for="type in types" :key="type" :value="type">{{ t(`moving.tickets.type.${type}`) }}</option>
                </select>
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
                            <tr v-if="loading"><td colspan="4" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="4" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.uuid" role="button" @click="open(row)">
                                <td class="ps-4 fw-semibold">{{ row.subject }}</td>
                                <td><span class="badge bg-light text-dark">{{ t(`moving.tickets.type.${row.type}`) }}</span></td>
                                <td class="text-wrap fs-13" style="min-width: 220px">{{ row.escalation_reason || '—' }}</td>
                                <td class="fs-12">{{ new Date(row.created_at).toLocaleString() }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="pagination && pagination.last_page > 1" class="card-footer d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-sm btn-light" :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)"><i class="ri-arrow-right-s-line"></i></button>
                <span class="fs-13">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
                <button type="button" class="btn btn-sm btn-light" :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)"><i class="ri-arrow-left-s-line"></i></button>
            </div>
        </div>

        <WalletModal :show="!!ticket" :title="ticket?.subject ?? ''" size="lg" @close="ticket = null">
            <template v-if="ticket">
                <div class="d-flex flex-column gap-2 mb-3" style="max-height: 320px; overflow-y: auto">
                    <div v-for="(m, i) in ticket.messages" :key="i" class="rounded-3 p-2 fs-13" :class="m.author === 'user' ? 'bg-light' : (m.author === 'admin' ? 'bg-primary-transparent' : 'bg-info-transparent')">
                        <div class="fs-11 text-muted mb-1">{{ t(`moving.tickets.author.${m.author}`) }} · {{ new Date(m.at).toLocaleString() }}</div>
                        {{ m.body }}
                    </div>
                </div>
                <form v-if="!['resolved', 'unresolved'].includes(ticket.status) && can('moving-tickets.update')" id="moving-ticket-form" @submit.prevent="act">
                    <label class="form-label">{{ t('moving.tickets.reply') }}</label>
                    <textarea v-model="form.body" rows="2" class="form-control mb-2"></textarea>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <select v-model="form.close" class="form-select form-select-sm">
                                <option :value="null">{{ t('moving.tickets.keep_open') }}</option>
                                <option value="resolved">{{ t('moving.tickets.status.resolved') }}</option>
                                <option value="unresolved">{{ t('moving.tickets.status.unresolved') }}</option>
                            </select>
                        </div>
                        <div v-if="form.close" class="col-md-8">
                            <input v-model="form.reason" type="text" class="form-control form-control-sm" :placeholder="t('moving.tickets.closing_reason')" required>
                        </div>
                    </div>
                </form>
                <div v-else class="alert alert-light border mb-0 fs-13">{{ t(`moving.tickets.status.${ticket.status}`) }} · {{ ticket.closing_reason }}</div>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="ticket = null">{{ t('cancel') }}</button>
                <button v-if="ticket && !['resolved', 'unresolved'].includes(ticket.status) && can('moving-tickets.update')" type="submit" form="moving-ticket-form" class="btn btn-primary" :disabled="busy">{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Moving tickets taken to Dorr (docs/moving_tasks.md B-15): answered and closed with a reason (REQ-063, REQ-064).
const { t } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();

const types = ['suggestion', 'inquiry', 'complaint', 'safety', 'inventory', 'delay', 'financial', 'note'];
const rows = ref([]);
const pagination = ref(null);
const loading = ref(false);
const busy = ref(false);
const ticket = ref(null);
const filters = reactive({ status: 'escalated', type: '' });
const form = reactive({ body: '', close: null, reason: '' });

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/moving-tickets', { params: { page, status: filters.status, type: filters.type || undefined } });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? null;
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        loading.value = false;
    }
}

async function open(row) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/moving-tickets/${row.uuid}`);
        ticket.value = data.data;
        Object.assign(form, { body: '', close: null, reason: '' });
    } catch (e) {
        showError(extractApiErrorMessage(e));
    }
}

async function act() {
    busy.value = true;
    try {
        const { data } = await adminAxios.post(`/api/admin/v1/moving-tickets/${ticket.value.uuid}/act`, form);
        ticket.value = data.data;
        showSuccess(t('moving.common.saved'));
        Object.assign(form, { body: '', close: null, reason: '' });
        load(pagination.value?.current_page ?? 1);
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        busy.value = false;
    }
}

onMounted(() => load());
</script>
