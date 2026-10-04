<template>
    <div>
        <WalletPageHeader :title="t('wallet.ledger.title')" :total="pagination?.total ?? null" />

        <div v-if="summary.length" class="row">
            <div v-for="entry in summary" :key="entry.currency_code" class="col-xl-4 col-md-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <div class="fw-semibold mb-2">{{ entry.currency_code }}</div>
                        <div class="d-flex justify-content-between"><span class="text-muted">{{ t('wallet.ledger.income') }}</span><span class="text-success fw-semibold">{{ fmtMinor(entry.income_minor) }}</span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">{{ t('wallet.ledger.expense') }}</span><span class="text-danger fw-semibold">{{ fmtMinor(entry.expense_minor) }}</span></div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between"><span class="fw-semibold">{{ t('wallet.ledger.net') }}</span><span class="fw-bold" :class="entry.net_minor < 0 ? 'text-danger' : 'text-success'">{{ fmtMinor(entry.net_minor) }}</span></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="filters.category" type="search" class="form-control" :placeholder="t('wallet.ledger.category_slug')">
                        <button
                            v-if="filters.category"
                            type="button"
                            class="btn btn-light border"
                            :title="t('wallet.common.clear_search')"
                            @click="filters.category = ''"
                        >
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                    <Select
                        v-model="filters.type"
                        :options="typeFilterOptions"
                        option-label="label"
                        option-value="value"
                        class="wallet-filter-select"
                    />
                    <AdminDatePicker v-model="filters.from" :placeholder="t('wallet.common.date_from')" />
                    <AdminDatePicker v-model="filters.to" :placeholder="t('wallet.common.date_to')" />
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('wallet.common.date') }}</th>
                                <th>{{ t('wallet.ledger.category') }}</th>
                                <th>{{ t('wallet.common.type') }}</th>
                                <th>{{ t('wallet.common.amount') }}</th>
                                <th>{{ t('wallet.common.note') }}</th>
                                <th>{{ t('wallet.wallets.country') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :columns="6" />
                            <tr v-else-if="!rows.length">
                                <td colspan="6" class="border-0">
                                    <div class="text-center py-5">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                            <i class="ri-file-list-3-line fs-2 text-primary"></i>
                                        </span>
                                        <p class="fw-semibold mb-1">{{ t('wallet.common.empty_title') }}</p>
                                        <p class="text-muted mb-0">{{ t('wallet.common.empty') }}</p>
                                    </div>
                                </td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" class="crm-contact">
                                    <td class="ps-4">{{ row.entry_date }}</td>
                                    <td>
                                        <span class="fw-semibold d-block">{{ row.category?.name }}</span>
                                        <span class="d-block text-muted fs-11">{{ row.category?.slug }}</span>
                                    </td>
                                    <td><span class="badge" :class="row.type === 'income' ? 'bg-success-transparent' : 'bg-danger-transparent'">{{ t(`wallet.ledger.${row.type}`) }}</span></td>
                                    <td class="fw-semibold" dir="ltr">{{ fmtMinor(row.amount_minor, row.currency_code) }}</td>
                                    <td class="text-wrap" style="max-width: 320px;">{{ row.note }}</td>
                                    <td><span v-if="row.country_code" class="badge bg-primary-transparent">{{ row.country_code }}</span><span v-else class="text-muted">-</span></td>
                                </tr>
                            </template>
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
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import AdminDatePicker from '../../../../../../../components/ui/AdminDatePicker.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useWalletList from '../../../../../../../composables/useWalletList';
import { fmtMinor } from '../../../../../../../utils/walletMoney';

const { t } = useI18n();

const typeFilterOptions = computed(() => [
    { value: '', label: t('wallet.ledger.all_types') },
    { value: 'income', label: t('wallet.ledger.income') },
    { value: 'expense', label: t('wallet.ledger.expense') },
]);

const { rows, loading, pagination, filters, fetch } = useWalletList('financial-entries', {
    defaults: { type: '', category: '', from: '', to: '' },
});

const summary = ref([]);

async function loadSummary() {
    try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));
        const { data } = await adminAxios.get('/api/admin/v1/financial-entries/summary', { params });

        summary.value = data.data ?? [];
    } catch {
        summary.value = [];
    }
}

watch(() => ({ ...filters }), loadSummary, { deep: true });

onMounted(() => {
    fetch(1);
    loadSummary();
});
</script>
