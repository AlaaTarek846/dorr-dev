<template>
    <div>
        <WalletPageHeader :title="t('moving.establishments.title')" :section="t('sidebar.moving')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="filters.search" type="search" class="form-control" :placeholder="t('moving.establishments.search')">
                        <button v-if="filters.search" type="button" class="btn btn-light border catalog-search-clear" :title="t('wallet.common.clear_search')" @click="filters.search = ''">
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                    <Select
                        v-model="filters.status"
                        :options="statusOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('moving.establishments.all_statuses')"
                        show-clear
                        class="wallet-filter-select"
                    />
                    <Select
                        v-model="filters.type"
                        :options="typeOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('moving.establishments.all_types')"
                        show-clear
                        class="wallet-filter-select"
                    />
                    <div class="form-check form-switch ms-2 mb-0">
                        <input id="est-needs-review" v-model="filters.needs_review" class="form-check-input" type="checkbox" :true-value="1" :false-value="''">
                        <label class="form-check-label fs-13" for="est-needs-review">{{ t('moving.establishments.needs_review') }}</label>
                    </div>
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
                            <TableSkeleton v-if="loading" :rows="6" :layout="['two-lines', 'two-lines', 'text', 'badge', 'badge', 'text']" />
                            <tr v-else-if="!rows.length">
                                <td colspan="6" class="text-center text-muted py-5"><i class="ri-store-2-line fs-24 d-block mb-1"></i>{{ t('wallet.common.empty') }}</td>
                            </tr>
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
                                <td class="fs-12">{{ formatDateTime(row.submitted_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useWalletList from '../../../../../../../composables/useWalletList';
import { formatDateTime as walletDateTime } from '../../../../../../../utils/walletMoney';

// Provider establishments and the review queue (docs/moving_tasks.md A-11): what waits for a decision comes first.
const { t, locale } = useI18n();
const router = useRouter();

const statuses = ['draft', 'under_review', 'approved', 'rejected', 'suspended'];
const statusOptions = computed(() => statuses.map((s) => ({ value: s, label: t(`moving.establishment_status.${s}`) })));
const typeOptions = computed(() => ['individual', 'company'].map((type) => ({ value: type, label: t(`moving.establishment_type.${type}`) })));
const { rows, loading, pagination, filters, fetch } = useWalletList('provider-establishments', {
    defaults: { search: '', status: '', type: '', needs_review: 1 },
    perPage: 20,
});
const formatDateTime = (iso) => (iso ? walletDateTime(iso, locale.value) : '—');

const statusClass = (status) => ({
    approved: 'bg-success-transparent', under_review: 'bg-warning-transparent', rejected: 'bg-danger-transparent',
    suspended: 'bg-danger-transparent', draft: 'bg-light text-dark',
}[status] ?? 'bg-light text-dark');

function open(row) {
    router.push({ name: 'admin.moving.establishments.show', params: { uuid: row.uuid } });
}

onMounted(() => fetch(1));
</script>
