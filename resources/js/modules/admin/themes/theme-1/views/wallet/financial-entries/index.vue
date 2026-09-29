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
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <select v-model="filters.type" class="form-select form-select-sm w-auto">
                    <option value="">{{ t('wallet.ledger.all_types') }}</option>
                    <option value="income">{{ t('wallet.ledger.income') }}</option>
                    <option value="expense">{{ t('wallet.ledger.expense') }}</option>
                </select>
                <input v-model="filters.category" type="text" class="form-control form-control-sm w-auto" :placeholder="t('wallet.ledger.category_slug')">
                <input v-model="filters.from" type="date" class="form-control form-control-sm w-auto">
                <input v-model="filters.to" type="date" class="form-control form-control-sm w-auto">
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped mb-0">
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
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id">
                                    <td class="ps-4">{{ row.entry_date }}</td>
                                    <td><span class="fw-semibold">{{ row.category?.name }}</span> <span class="text-muted fs-11">{{ row.category?.slug }}</span></td>
                                    <td><span class="badge" :class="row.type === 'income' ? 'bg-success-transparent' : 'bg-danger-transparent'">{{ t(`wallet.ledger.${row.type}`) }}</span></td>
                                    <td class="fw-semibold" dir="ltr">{{ fmtMinor(row.amount_minor, row.currency_code) }}</td>
                                    <td class="text-wrap" style="max-width: 320px;">{{ row.note }}</td>
                                    <td>{{ row.country_code || '-' }}</td>
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
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useWalletList from '../../../../../../../composables/useWalletList';
import { fmtMinor } from '../../../../../../../utils/walletMoney';

const { t } = useI18n();

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
