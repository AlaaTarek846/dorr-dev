<template>
    <div>
        <WalletPageHeader :title="t('watch_earn.finance.title')" :section="t('sidebar.watch_earn')" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <Select
                        v-model="filters.country_id"
                        :options="countryOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('watch_earn.finance.country')"
                        filter
                        :filter-placeholder="t('search_placeholder')"
                        class="wallet-filter-select"
                    />
                    <AdminDatePicker v-model="filters.from" :placeholder="t('watch_earn.finance.from')" />
                    <AdminDatePicker v-model="filters.to" :placeholder="t('watch_earn.finance.to')" />
                </div>
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <button v-for="f in ['pdf', 'xlsx']" :key="f" type="button" class="btn btn-sm btn-outline-primary btn-wave" :disabled="!report || exporting" @click="download(f)">
                        <span v-if="exporting === f" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else :class="f === 'pdf' ? 'ri-file-pdf-2-line' : 'ri-file-excel-2-line'" class="me-1"></i>{{ t(`watch_earn.finance.export_${f}`) }}
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div v-if="loading" class="row g-3 placeholder-glow">
                    <div v-for="n in tiles.length" :key="n" class="col-6 col-md-4 col-xl-3">
                        <div class="border rounded-3 p-3 h-100">
                            <span class="placeholder col-6 placeholder-sm d-block mb-2"></span>
                            <span class="placeholder col-8 d-block"></span>
                        </div>
                    </div>
                </div>
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
                <div v-else class="text-center text-muted py-5">
                    <i class="ri-funds-line fs-24 d-block mb-1"></i>{{ countriesLoaded && !countries.length ? t('watch_earn.finance.no_countries') : t('wallet.common.empty') }}
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import AdminDatePicker from '../../../../../../../components/ui/AdminDatePicker.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { money } from '../money';

const { t, locale } = useI18n();
const { showError } = useToast();

const countries = ref([]);
const countriesLoaded = ref(false);
const report = ref(null);
const loading = ref(false);
const today = new Date();
const filters = reactive({
    country_id: null,
    from: new Date(today.getTime() - 30 * 86400000).toISOString().slice(0, 10),
    to: today.toISOString().slice(0, 10),
});
const countryOptions = computed(() => countries.value.map((c) => ({ value: c.country.id, label: c.country.name || c.country.code })));
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
    if (!filters.country_id || !filters.from || !filters.to) return;
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/watch-earn/finance', { params: filters });
        report.value = data.data;
    } catch (error) {
        report.value = null;
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

const exporting = ref(null);

/** The same numbers as a PDF / Excel file (WE-043). */
async function download(format) {
    exporting.value = format;
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
        exporting.value = null;
    }
}

// Any filter change reloads the numbers once (the country list is loaded first).
watch(() => [filters.country_id, filters.from, filters.to], load);

onMounted(async () => {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/watch-earn/settings');
        countries.value = data.data ?? [];
        filters.country_id = countries.value[0]?.country.id ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        countriesLoaded.value = true;
    }
});
</script>

<style scoped>
.we-fin { transition: transform .15s ease, box-shadow .15s ease; }
.we-fin:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0, 0, 0, .08); }
</style>
