<template>
    <div>
        <WalletPageHeader :title="t('adminMarketing.packages.title')" :section="t('adminMarketing.sidebar')" :total="loading ? null : rows.length" />

        <div class="alert alert-light border fs-13">{{ t('adminMarketing.packages.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <FlStatusFilter v-model="filter" :options="filterOptions" />
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="search" type="search" class="form-control" :placeholder="t('adminMarketing.packages.search')">
                    </div>
                </div>
                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('adminMarketing.packages.add') }}
                </button>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('adminMarketing.packages.name') }}</th>
                                <th>{{ t('adminMarketing.packages.period') }}</th>
                                <th>{{ t('adminMarketing.packages.limits') }}</th>
                                <th>{{ t('adminMarketing.packages.prices') }}</th>
                                <th>{{ t('adminMarketing.packages.subscribers') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th v-if="showActionsColumn" class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="5" :layout="['two-lines', 'text', 'text', 'text', 'text', 'badge', 'actions']" />
                            <tr v-else-if="!shown.length">
                                <td colspan="7" class="border-0">
                                    <FlEmpty icon="ri-vip-crown-2-line" :title="t('adminMarketing.packages.empty_title')" :text="t('adminMarketing.packages.empty')" />
                                </td>
                            </tr>
                            <tr v-for="row in shown" v-else :key="row.id">
                                <td class="ps-4">
                                    <span class="fw-semibold d-block">{{ nameOf(row) }}</span>
                                    <code class="fs-11 text-muted">{{ row.code }}</code>
                                </td>
                                <td>{{ t('adminMarketing.packages.days', { n: row.period_days }) }}</td>
                                <td class="fs-12 text-wrap limits">
                                    <span v-for="key in LIMITS" :key="key" class="d-block">
                                        {{ t(`adminMarketing.packages.limit.${key}`) }}:
                                        <span class="fw-semibold">{{ row.limits?.[key] == null ? t('adminMarketing.packages.unlimited') : row.limits[key] }}</span>
                                    </span>
                                </td>
                                <td class="text-wrap prices">
                                    <span
                                        v-for="p in row.prices"
                                        :key="p.country_id"
                                        class="badge me-1 mb-1"
                                        :class="p.status ? 'bg-light text-dark' : 'bg-secondary-transparent text-decoration-line-through'"
                                        dir="ltr"
                                    >{{ countryLabel(p.country_id) }} · {{ money(p.price_minor) }}</span>
                                    <span v-if="!row.prices?.length" class="text-muted fs-12">{{ t('adminMarketing.packages.no_prices') }}</span>
                                </td>
                                <td><span class="badge bg-light text-default">{{ row.subscribers ?? 0 }}</span></td>
                                <td>
                                    <div
                                        v-if="canUpdate"
                                        class="toggle toggle-success mb-0 catalog-status-toggle"
                                        :class="{ on: row.is_active, 'catalog-status-toggle--loading': togglingId === row.id }"
                                        role="button"
                                        tabindex="0"
                                        @click="toggleActive(row)"
                                        @keydown.enter.space.prevent="toggleActive(row)"
                                    ><span></span></div>
                                    <span v-else class="badge" :class="row.is_active ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ row.is_active ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                </td>
                                <td v-if="showActionsColumn" class="text-end pe-4">
                                    <div class="btn-list justify-content-end">
                                        <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('adminMarketing.packages.edit')" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                        <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('adminMarketing.packages.delete')" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <WalletModal :show="showModal" :title="editingId ? t('adminMarketing.packages.edit') : t('adminMarketing.packages.add')" size="lg" @close="closeModal">
            <form id="marketing-package-form" class="catalog-scroll-form" novalidate @submit.prevent="save">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ t('adminMarketing.packages.code') }} <span class="text-danger">*</span></label>
                        <input v-model.trim="form.code" type="text" class="form-control" dir="ltr" maxlength="40" :class="{ 'is-invalid': errors.code }">
                        <div class="form-text">{{ t('adminMarketing.packages.code_hint') }}</div>
                        <div v-if="errors.code" class="invalid-feedback d-block">{{ errors.code }}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ t('adminMarketing.packages.period_days') }} <span class="text-danger">*</span></label>
                        <input v-model.number="form.period_days" type="number" min="1" max="366" class="form-control" :class="{ 'is-invalid': errors.period_days }">
                        <div v-if="errors.period_days" class="invalid-feedback d-block">{{ errors.period_days }}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ t('adminMarketing.packages.sort_order') }}</label>
                        <input v-model.number="form.sort_order" type="number" min="0" max="1000" class="form-control">
                    </div>
                </div>

                <CatalogTranslationTabs
                    :languages="languages"
                    :active-locale="activeLocale"
                    :translation-tab-class="tabClass"
                    :translation-tab-feedback="tabFeedback"
                    @update:active-locale="activeLocale = $event"
                />
                <div v-if="activeLocale" class="mb-3">
                    <label class="form-label">{{ t('adminMarketing.packages.name') }} <span class="text-danger">*</span></label>
                    <input v-model="form.name[activeLocale]" type="text" class="form-control" maxlength="120" :dir="activeLocale === 'ar' ? 'rtl' : 'ltr'">
                    <label class="form-label mt-2">{{ t('adminMarketing.packages.description') }}</label>
                    <textarea v-model="form.description[activeLocale]" class="form-control" rows="2" maxlength="1000" :dir="activeLocale === 'ar' ? 'rtl' : 'ltr'"></textarea>
                    <div v-if="errors.name" class="text-danger fs-12 mt-1">{{ errors.name }}</div>
                </div>

                <h6 class="fw-semibold mb-1">{{ t('adminMarketing.packages.limits') }}</h6>
                <div class="form-text mb-2">{{ t('adminMarketing.packages.limits_hint') }}</div>
                <div class="row g-3 mb-3">
                    <div v-for="key in LIMITS" :key="key" class="col-md-3 col-6">
                        <label class="form-label">{{ t(`adminMarketing.packages.limit.${key}`) }}</label>
                        <input v-model="form.limits[key]" type="number" :min="key === 'projects' ? 1 : 0" class="form-control" :placeholder="t('adminMarketing.packages.unlimited')">
                    </div>
                    <div v-if="errors.limits" class="col-12 text-danger fs-12">{{ errors.limits }}</div>
                </div>

                <label class="form-label d-flex align-items-center flex-wrap">
                    {{ t('adminMarketing.packages.prices') }}
                    <small class="text-muted ms-2">{{ t('adminMarketing.packages.prices_hint') }}</small>
                </label>
                <div v-for="(price, i) in form.prices" :key="i" class="row g-2 mb-2 align-items-center">
                    <div class="col-md-5 col-12">
                        <select v-model="price.country_id" class="form-select form-select-sm">
                            <option :value="null" disabled>{{ t('adminMarketing.common.country') }}</option>
                            <option v-for="c in countries" :key="c.id" :value="c.id" :disabled="form.prices.some((p, j) => j !== i && p.country_id === c.id)">{{ c.name }} ({{ c.code }})</option>
                        </select>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="input-group input-group-sm">
                            <input v-model="price.amount" type="number" min="0.01" step="0.01" class="form-control" dir="ltr" :placeholder="t('adminMarketing.packages.price')">
                        </div>
                    </div>
                    <div class="col-md-2 col-4">
                        <div class="form-check form-switch mb-0">
                            <input :id="`mk-price-${i}`" v-model="price.status" class="form-check-input" type="checkbox">
                            <label class="form-check-label fs-12" :for="`mk-price-${i}`">{{ t('wallet.common.active') }}</label>
                        </div>
                    </div>
                    <div class="col-md-1 col-2 text-end">
                        <button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="form.prices.splice(i, 1)"><i class="ri-close-line"></i></button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-light" @click="form.prices.push({ country_id: null, amount: '', status: true })">
                    <i class="ri-add-line me-1"></i>{{ t('adminMarketing.packages.add_country') }}
                </button>
                <div v-if="errors.prices" class="text-danger fs-12 mt-1">{{ errors.prices }}</div>

                <div class="form-check form-switch mt-3">
                    <input id="mk-pkg-active" v-model="form.is_active" class="form-check-input" type="checkbox">
                    <label class="form-check-label" for="mk-pkg-active">{{ t('adminMarketing.packages.is_active') }}</label>
                </div>
                <div v-if="errors.general" class="text-danger mt-2 fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="closeModal">{{ t('cancel') }}</button>
                <button type="submit" form="marketing-package-form" class="btn btn-primary" :disabled="saving">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>

        <ConfirmDeleteModal
            :show="deleteConfirm.state.show"
            :title="deleteConfirm.state.title"
            :message="deleteConfirm.state.message"
            :loading="deleteConfirm.state.loading"
            @close="deleteConfirm.close()"
            @confirm="confirmRemove"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../../components/catalog/CatalogTranslationTabs.vue';
import FlEmpty from '../../../../../../../components/freelance/FlEmpty.vue';
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import ConfirmDeleteModal from '../../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import { useCatalogPermissions } from '../../../../../../../composables/useCatalogPermissions';
import { useConfirmDelete } from '../../../../../../../composables/useConfirmDelete';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';

// AI Marketing Director packages (MK-A15, D5): what a subscriber may do per period and the price in each country.
// Prices are stored in the minor unit (×100): the screen shows and takes the major unit. A package someone bought is
// switched off and soft-deleted, never removed (the backend decides).
const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();
const { canCreate, canUpdate, canDelete, showActionsColumn } = useCatalogPermissions('marketing-packages');
const deleteConfirm = useConfirmDelete();
const languagesStore = useAvailableLanguagesStore();
const endpoint = '/api/admin/v1/marketing/packages';

const LIMITS = ['projects', 'research_runs', 'plan_generations', 'members', 'content_generations'];
// The backend keeps the name and description in Arabic and English only.
const LOCALES = ['ar', 'en'];
const FALLBACK_LANGUAGES = [{ code: 'ar', name: 'العربية' }, { code: 'en', name: 'English' }];

const rows = ref([]);
const loading = ref(false);
const countries = ref([]);
const filter = ref('all');
const search = ref('');
const togglingId = ref(null);

const languages = computed(() => {
    const fromStore = (languagesStore.items ?? []).filter((lang) => LOCALES.includes(lang.code));
    return fromStore.length === LOCALES.length ? fromStore : FALLBACK_LANGUAGES;
});
const activeLocale = ref('ar');

const filterOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), count: rows.value.length, tone: 'all' },
    { value: 'active', label: t('wallet.common.active'), count: rows.value.filter((r) => r.is_active).length, tone: 'active' },
    { value: 'inactive', label: t('wallet.common.inactive'), count: rows.value.filter((r) => !r.is_active).length, tone: 'inactive' },
]);
const shown = computed(() => {
    const q = search.value.trim().toLowerCase();
    return rows.value.filter((r) => (filter.value === 'all' || (filter.value === 'active') === !!r.is_active)
        && (!q || (r.code ?? '').toLowerCase().includes(q) || Object.values(r.name ?? {}).some((n) => (n ?? '').toLowerCase().includes(q))));
});

const nameOf = (row) => row.name?.[locale.value] || row.name?.en || row.name?.ar || row.code;
const money = (minor) => (minor == null ? '—' : (Number(minor) / 100).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const countryOf = (id) => countries.value.find((c) => c.id === id);
const countryLabel = (id) => countryOf(id)?.code ?? `#${id}`;

// ---- form
const showModal = ref(false);
const editingId = ref(null);
const saving = ref(false);
const errors = reactive({});
const emptyForm = () => ({
    code: '',
    period_days: 30,
    sort_order: rows.value.length,
    is_active: true,
    name: { ar: '', en: '' },
    description: { ar: '', en: '' },
    limits: Object.fromEntries(LIMITS.map((k) => [k, ''])),
    prices: [],
});
const form = reactive(emptyForm());

const filled = (code) => !!(form.name[code] || '').trim();
const tabClass = (code) => ({ active: activeLocale.value === code, 'catalog-lang-tab--error': !!errors.name && !filled(code), 'catalog-lang-tab--valid': filled(code) });
const tabFeedback = (code) => ({ show: filled(code) || !!errors.name, valid: filled(code) });

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function openCreate() {
    editingId.value = null;
    resetErrors();
    Object.assign(form, emptyForm());
    activeLocale.value = 'ar';
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    resetErrors();
    Object.assign(form, {
        code: row.code,
        period_days: row.period_days,
        sort_order: row.sort_order ?? 0,
        is_active: !!row.is_active,
        name: { ar: row.name?.ar ?? '', en: row.name?.en ?? '' },
        description: { ar: row.description?.ar ?? '', en: row.description?.en ?? '' },
        limits: Object.fromEntries(LIMITS.map((k) => [k, row.limits?.[k] ?? ''])),
        prices: (row.prices ?? []).map((p) => ({ country_id: p.country_id, amount: Number(p.price_minor) / 100, status: !!p.status })),
    });
    activeLocale.value = 'ar';
    showModal.value = true;
}

function closeModal() {
    if (!saving.value) showModal.value = false;
}

const limitValue = (v) => (v === '' || v == null ? null : Number(v));

async function save() {
    resetErrors();
    saving.value = true;
    const body = {
        code: form.code,
        period_days: form.period_days,
        sort_order: form.sort_order || 0,
        is_active: form.is_active,
        name: { ar: form.name.ar, en: form.name.en },
        description: { ar: form.description.ar || null, en: form.description.en || null },
        limits: Object.fromEntries(LIMITS.map((k) => [k, limitValue(form.limits[k])])),
        prices: form.prices.filter((p) => p.country_id).map((p) => ({ country_id: p.country_id, price_minor: Math.round(Number(p.amount || 0) * 100), status: !!p.status })),
    };
    try {
        const response = editingId.value ? await adminAxios.put(`${endpoint}/${editingId.value}`, body) : await adminAxios.post(endpoint, body);
        showSuccess(extractApiMessage(response, t('toast.success')));
        showModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => {
            const field = ['name', 'description', 'limits', 'prices'].find((f) => key.startsWith(f)) ?? key;
            errors[field === 'description' ? 'name' : field] ??= Array.isArray(messages) ? messages[0] : messages;
        });
        if (!Object.keys(bag).length) errors.general = extractApiErrorMessage(error, t('toast.error'));
        if (errors.name) activeLocale.value = LOCALES.find((code) => !filled(code)) ?? activeLocale.value;
    } finally {
        saving.value = false;
    }
}

async function toggleActive(row) {
    if (togglingId.value) return;
    togglingId.value = row.id;
    try {
        const response = await adminAxios.put(`${endpoint}/${row.id}`, { is_active: !row.is_active });
        row.is_active = response.data?.data?.is_active ?? !row.is_active;
        showSuccess(extractApiMessage(response, t('toast.success')));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        togglingId.value = null;
    }
}

// ---- delete
function remove(row) {
    const message = row.subscribers
        ? t('adminMarketing.packages.confirm_delete_sold', { name: nameOf(row) })
        : t('adminMarketing.packages.confirm_delete', { name: nameOf(row) });
    deleteConfirm.open({ title: t('adminMarketing.packages.delete'), message, payload: row });
}

async function confirmRemove() {
    const row = deleteConfirm.state.payload;
    if (!row) return;
    deleteConfirm.setLoading(true);
    try {
        const response = await adminAxios.delete(`${endpoint}/${row.id}`);
        showSuccess(extractApiMessage(response, t('toast.success')));
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
        load();
    } catch (error) {
        deleteConfirm.setLoading(false);
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(endpoint);
        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

// The country list for the price rows: the countries catalog, or the marketing switch options if that is not allowed.
async function loadCountries() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/countries/dropdown');
        countries.value = data.data ?? [];
        return;
    } catch {
        // fall through
    }
    try {
        const { data } = await adminAxios.get('/api/admin/v1/marketing/feature-rules/options');
        countries.value = data.data?.countries ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

watch(showModal, (open) => {
    if (open && !countries.value.length) loadCountries();
});

onMounted(async () => {
    load();
    loadCountries();
    try {
        await languagesStore.fetch();
    } catch {
        // the fallback Arabic / English tabs are used
    }
});
</script>

<style scoped>
.limits,
.prices {
    max-width: 320px;
    min-width: 180px;
}
</style>
