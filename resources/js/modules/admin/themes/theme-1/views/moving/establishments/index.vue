<template>
    <div>
        <WalletPageHeader :title="t('moving.establishments.title')" :section="t('sidebar.moving')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center py-3">
                <input v-model="filters.search" type="search" class="form-control form-control-sm" style="max-width: 240px" :placeholder="t('moving.establishments.search')" @keyup.enter="load(1)">
                <select v-model="filters.status" class="form-select form-select-sm" style="max-width: 180px" @change="load(1)">
                    <option value="">{{ t('moving.establishments.all_statuses') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ t(`moving.establishment_status.${s}`) }}</option>
                </select>
                <select v-model="filters.type" class="form-select form-select-sm" style="max-width: 160px" @change="load(1)">
                    <option value="">{{ t('moving.establishments.all_types') }}</option>
                    <option value="individual">{{ t('moving.establishment_type.individual') }}</option>
                    <option value="company">{{ t('moving.establishment_type.company') }}</option>
                </select>
                <div class="form-check form-switch ms-2">
                    <input id="est-needs-review" v-model="filters.needs_review" class="form-check-input" type="checkbox" @change="load(1)">
                    <label class="form-check-label fs-13" for="est-needs-review">{{ t('moving.establishments.needs_review') }}</label>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('moving.establishments.name') }}</th>
                                <th>{{ t('moving.establishments.owner') }}</th>
                                <th>{{ t('moving.establishments.country') }}</th>
                                <th>{{ t('moving.establishments.status') }}</th>
                                <th>{{ t('moving.establishments.pending') }}</th>
                                <th>{{ t('moving.establishments.submitted_at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.uuid" role="button" @click="open(row)">
                                <td class="ps-4">
                                    <div class="fw-semibold">{{ row.name }}</div>
                                    <span class="badge bg-light text-dark fs-11">{{ t(`moving.establishment_type.${row.type}`) }}</span>
                                </td>
                                <td>
                                    <div>{{ row.owner?.name || '—' }}</div>
                                    <div class="fs-12 text-muted" dir="ltr">{{ row.owner?.phone }}</div>
                                </td>
                                <td>{{ row.country || '—' }}</td>
                                <td><span class="badge" :class="statusClass(row.status)">{{ t(`moving.establishment_status.${row.status}`) }}</span></td>
                                <td class="fs-12">
                                    <span v-if="row.vehicles_pending" class="badge bg-warning-transparent me-1"><i class="ri-truck-line me-1"></i>{{ row.vehicles_pending }}</span>
                                    <span v-if="row.documents_pending" class="badge bg-warning-transparent"><i class="ri-file-list-3-line me-1"></i>{{ row.documents_pending }}</span>
                                    <span v-if="!row.vehicles_pending && !row.documents_pending" class="text-muted">—</span>
                                </td>
                                <td class="fs-12">{{ row.submitted_at ? new Date(row.submitted_at).toLocaleString() : '—' }}</td>
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
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';

// Provider establishments and the review queue (docs/moving_tasks.md A-11): what waits for a decision comes first.
const { t } = useI18n();
const router = useRouter();
const { showError } = useToast();

const statuses = ['draft', 'under_review', 'approved', 'rejected', 'suspended'];
const rows = ref([]);
const pagination = ref(null);
const loading = ref(false);
const filters = reactive({ search: '', status: '', type: '', needs_review: true });

const statusClass = (status) => ({
    approved: 'bg-success-transparent', under_review: 'bg-warning-transparent', rejected: 'bg-danger-transparent',
    suspended: 'bg-danger-transparent', draft: 'bg-light text-dark',
}[status] ?? 'bg-light text-dark');

function open(row) {
    router.push({ name: 'admin.moving.establishments.show', params: { uuid: row.uuid } });
}

async function load(page = 1) {
    loading.value = true;
    try {
        const params = { page, ...filters, needs_review: filters.needs_review ? 1 : undefined };
        Object.keys(params).forEach((k) => (params[k] === '' || params[k] === undefined) && delete params[k]);
        const { data } = await adminAxios.get('/api/admin/v1/provider-establishments', { params });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? null;
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());
</script>
