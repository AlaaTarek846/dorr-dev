<template>
    <div>
        <WalletPageHeader :title="t('watch_earn.finance.title')" :section="t('sidebar.watch_earn')" />

        <div class="card custom-card">
            <div class="card-header d-flex flex-wrap align-items-end gap-2 py-3">
                <div>
                    <label class="form-label fs-12 mb-1">{{ t('watch_earn.finance.country') }}</label>
                    <select v-model="filters.country_id" class="form-select form-select-sm" style="min-width: 180px;" @change="load">
                        <option v-for="c in countries" :key="c.country.id" :value="c.country.id">{{ c.country.name || c.country.code }}</option>
                    </select>
                </div>
                <div>
                    <label class="form-label fs-12 mb-1">{{ t('watch_earn.finance.from') }}</label>
                    <input v-model="filters.from" type="date" class="form-control form-control-sm" @change="load">
                </div>
                <div>
                    <label class="form-label fs-12 mb-1">{{ t('watch_earn.finance.to') }}</label>
                    <input v-model="filters.to" type="date" class="form-control form-control-sm" @change="load">
                </div>
                <div class="ms-auto d-flex gap-2">
                    <button v-for="f in ['pdf', 'xlsx']" :key="f" type="button" class="btn btn-sm btn-outline-primary" :disabled="!report || exporting" @click="download(f)">
                        <i :class="f === 'pdf' ? 'ri-file-pdf-2-line' : 'ri-file-excel-2-line'" class="me-1"></i>{{ t(`watch_earn.finance.export_${f}`) }}
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>
                <div v-else-if="report" class="row g-3">
                    <div v-for="tile in tiles" :key="tile.key" class="col-6 col-md-4 col-xl-3">
                        <div class="border rounded-3 p-3 h-100 we-fin">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i :class="`${tile.icon} fs-18 ${tile.class}`"></i>
                                <span class="fs-12 text-muted">{{ t(`watch_earn.finance.${tile.key}`) }}</span>
                            </div>
                            <div class="fs-18 fw-semibold">{{ tile.money ? money(report[tile.key], currency, locale) : report[tile.key] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { money } from '../money';

const { t, locale } = useI18n();
const { showError } = useToast();

const countries = ref([]);
const report = ref(null);
const loading = ref(false);
const today = new Date();
const filters = reactive({
    country_id: null,
    from: new Date(today.getTime() - 30 * 86400000).toISOString().slice(0, 10),
    to: today.toISOString().slice(0, 10),
});
const currency = computed(() => countries.value.find((c) => c.country.id === filters.country_id)?.currency ?? { decimals: 2 });
const tiles = [
    { key: 'platform_revenue_minor', icon: 'ri-funds-line', class: 'text-success', money: true },
    { key: 'spent_minor', icon: 'ri-shopping-bag-3-line', class: 'text-primary', money: true },
    { key: 'rewards_paid_minor', icon: 'ri-gift-line', class: 'text-info', money: true },
    { key: 'rewards_waiting_minor', icon: 'ri-time-line', class: 'text-warning', money: true },
    { key: 'ad_wallets_free_minor', icon: 'ri-wallet-3-line', class: 'text-secondary', money: true },
    { key: 'ads_locked_minor', icon: 'ri-lock-2-line', class: 'text-danger', money: true },
    { key: 'completed_views', icon: 'ri-eye-line', class: 'text-primary', money: false },
];

async function load() {
    if (!filters.country_id) return;
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/watch-earn/finance', { params: filters });
        report.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

const exporting = ref(false);

/** The same numbers as a PDF / Excel file (WE-043). */
async function download(format) {
    exporting.value = true;
    try {
        const { data, headers } = await adminAxios.get('/api/admin/v1/watch-earn/finance/export', { params: { ...filters, format }, responseType: 'blob' });
        const name = /filename="([^"]+)"/.exec(headers['content-disposition'] || '')?.[1] || `watch-earn-finance.${format}`;
        const url = URL.createObjectURL(data);
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        link.click();
        URL.revokeObjectURL(url);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        exporting.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/watch-earn/settings');
        countries.value = data.data ?? [];
        filters.country_id = countries.value[0]?.country.id ?? null;
        await load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>

<style scoped>
.we-fin { transition: transform .15s ease, box-shadow .15s ease; }
.we-fin:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0, 0, 0, .08); }
</style>
