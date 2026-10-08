<template>
    <div>
        <WalletPageHeader :title="t('discover.catalog.title')" :section="t('sidebar.discover')" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <ul class="nav nav-pills nav-style-3 gap-1">
                    <li class="nav-item"><button type="button" class="nav-link py-1 px-3" :class="{ active: tab === 'categories' }" @click="tab = 'categories'"><i class="ri-price-tag-3-line me-1"></i>{{ t('discover.catalog.categories') }}</button></li>
                    <li class="nav-item"><button type="button" class="nav-link py-1 px-3" :class="{ active: tab === 'cities' }" @click="tab = 'cities'"><i class="ri-building-2-line me-1"></i>{{ t('discover.catalog.cities') }}</button></li>
                </ul>
                <select v-if="tab === 'cities'" v-model="countryFilter" class="form-select form-select-sm" style="max-width: 200px;">
                    <option value="">{{ t('discover.catalog.all_countries') }}</option>
                    <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openCreate">
                    <i class="ri-add-line me-1 align-middle"></i>{{ tab === 'categories' ? t('discover.catalog.add_category') : t('discover.catalog.add_city') }}
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.common.name') }}</th>
                                <th v-if="tab === 'categories'">{{ t('discover.catalog.key') }}</th>
                                <th v-if="tab === 'categories'">{{ t('discover.catalog.events') }}</th>
                                <th v-if="tab === 'cities'">{{ t('discover.catalog.country') }}</th>
                                <th v-if="tab === 'cities'">{{ t('discover.catalog.timezone') }}</th>
                                <th>{{ t('chat.common.sort_order') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="7" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!visibleRows.length"><td colspan="7" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in visibleRows" :key="row.id">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <span v-if="tab === 'categories'" class="avatar avatar-sm avatar-rounded" :style="{ background: row.color || '#eef' }"><span class="fs-15">{{ row.emoji || '📍' }}</span></span>
                                            <span class="fw-semibold">{{ row.name || `#${row.id}` }}</span>
                                        </div>
                                    </td>
                                    <td v-if="tab === 'categories'"><code>{{ row.key }}</code></td>
                                    <td v-if="tab === 'categories'"><span class="badge bg-info-transparent">{{ row.events_count }}</span></td>
                                    <td v-if="tab === 'cities'">{{ row.country }} <span class="text-muted fs-11">{{ row.country_code }}</span></td>
                                    <td v-if="tab === 'cities'" dir="ltr" class="text-start">{{ row.timezone }}</td>
                                    <td>{{ row.sort_order }}</td>
                                    <td>
                                        <div v-if="canUpdate" class="toggle toggle-success mb-0" :class="{ on: row.status }" role="button" @click="toggleStatus(row)"><span></span></div>
                                        <span v-else class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ row.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                            <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <WalletModal :show="showModal" :title="modalTitle" size="md" @close="showModal = false">
            <form id="discover-catalog-form" @submit.prevent="save">
                <CatalogTranslationTabs :languages="languages" :active-locale="activeLocale" :translation-tab-class="tabClass" :translation-tab-feedback="tabFeedback" @update:active-locale="activeLocale = $event" />
                <div class="mb-3">
                    <label class="form-label">{{ t('chat.common.name') }} <span class="text-danger">*</span></label>
                    <input v-model="form.translations[activeLocale]" type="text" maxlength="80" class="form-control" :class="{ 'is-invalid': errors.translations && !form.translations[activeLocale] }">
                    <div v-if="errors.translations" class="invalid-feedback d-block">{{ errors.translations }}</div>
                </div>
                <template v-if="tab === 'categories'">
                    <div class="row g-2 mb-3">
                        <div class="col-5">
                            <label class="form-label">{{ t('discover.catalog.key') }} <span class="text-danger">*</span></label>
                            <input v-model="form.key" type="text" maxlength="40" dir="ltr" class="form-control" :class="{ 'is-invalid': errors.key }" :placeholder="'concerts'">
                            <div v-if="errors.key" class="invalid-feedback">{{ errors.key }}</div>
                        </div>
                        <div class="col-3">
                            <label class="form-label">{{ t('discover.catalog.emoji') }}</label>
                            <input v-model="form.emoji" type="text" maxlength="16" class="form-control text-center">
                        </div>
                        <div class="col-4">
                            <label class="form-label">{{ t('discover.catalog.color') }}</label>
                            <input v-model="form.color" type="color" class="form-control form-control-color w-100">
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">{{ t('discover.catalog.country') }} <span class="text-danger">*</span></label>
                            <select v-model="form.country_id" class="form-select" :class="{ 'is-invalid': errors.country_id }">
                                <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">{{ t('discover.catalog.timezone') }} <span class="text-danger">*</span></label>
                            <input v-model="form.timezone" type="text" list="discover-zones" dir="ltr" class="form-control" :class="{ 'is-invalid': errors.timezone }" placeholder="Asia/Riyadh">
                            <datalist id="discover-zones"><option v-for="z in zones" :key="z" :value="z"></option></datalist>
                            <div v-if="errors.timezone" class="invalid-feedback">{{ errors.timezone }}</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label">{{ t('discover.catalog.lat') }}</label>
                            <input v-model="form.lat" type="number" step="any" dir="ltr" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label">{{ t('discover.catalog.lng') }}</label>
                            <input v-model="form.lng" type="number" step="any" dir="ltr" class="form-control">
                        </div>
                    </div>
                </template>
                <div class="row g-2 align-items-end">
                    <div class="col-5">
                        <label class="form-label">{{ t('chat.common.sort_order') }}</label>
                        <input v-model.number="form.sort_order" type="number" min="0" class="form-control">
                    </div>
                    <div class="col-7">
                        <div class="form-check form-switch mb-2"><input id="dc-status" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="dc-status">{{ t('wallet.common.active') }}</label></div>
                    </div>
                </div>
                <div v-if="errors.general" class="text-danger mt-2 fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="discover-catalog-form" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../../components/catalog/CatalogTranslationTabs.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const languagesStore = useAvailableLanguagesStore();

const tab = ref('categories');
const perm = computed(() => (tab.value === 'categories' ? 'discover-categories' : 'discover-cities'));
const endpoint = computed(() => `/api/admin/v1/${perm.value}`);
const canCreate = computed(() => can(`${perm.value}.create`));
const canUpdate = computed(() => can(`${perm.value}.update`));
const canDelete = computed(() => can(`${perm.value}.delete`));

const zones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];
const languages = computed(() => languagesStore.items);
const activeLocale = ref('');
const rows = ref([]);
const countries = ref([]);
const countryFilter = ref('');
const loading = ref(false);
const visibleRows = computed(() => (tab.value === 'cities' && countryFilter.value ? rows.value.filter((r) => r.country_id === countryFilter.value) : rows.value));

const showModal = ref(false);
const editingId = ref(null);
const saving = ref(false);
const errors = reactive({});
const blank = () => ({ key: '', emoji: '', color: '#2E86C1', country_id: null, timezone: '', lat: '', lng: '', sort_order: 0, status: true, translations: {} });
const form = reactive(blank());
const modalTitle = computed(() => {
    const what = tab.value === 'categories' ? 'category' : 'city';

    return editingId.value ? t(`discover.catalog.edit_${what}`) : t(`discover.catalog.add_${what}`);
});

function tabClass(code) {
    const filled = !!(form.translations[code] || '').trim();

    return { active: activeLocale.value === code, 'catalog-lang-tab--error': !!errors.translations && !filled, 'catalog-lang-tab--valid': filled };
}

function tabFeedback(code) {
    const filled = !!(form.translations[code] || '').trim();

    return { show: filled || !!errors.translations, valid: filled };
}

function ensureTranslations() {
    languages.value.forEach((lang) => { form.translations[lang.code] ??= ''; });
    if (!activeLocale.value && languages.value.length) activeLocale.value = languages.value[0].code;
}

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function openCreate() {
    editingId.value = null;
    resetErrors();
    Object.assign(form, blank(), { sort_order: rows.value.length, country_id: countryFilter.value || countries.value[0]?.id || null });
    ensureTranslations();
    activeLocale.value = languages.value[0]?.code || '';
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    resetErrors();
    Object.assign(form, blank(), {
        key: row.key ?? '', emoji: row.emoji ?? '', color: row.color || '#2E86C1', country_id: row.country_id ?? null, timezone: row.timezone ?? '',
        lat: row.lat ?? '', lng: row.lng ?? '', sort_order: row.sort_order ?? 0, status: !!row.status,
    });
    ensureTranslations();
    (row.translations ?? []).forEach((tr) => { form.translations[tr.locale] = tr.name ?? ''; });
    activeLocale.value = languages.value[0]?.code || '';
    showModal.value = true;
}

async function save() {
    resetErrors();
    saving.value = true;
    const body = {
        sort_order: form.sort_order || 0,
        status: form.status,
        translations: languages.value.map((lang) => ({ locale: lang.code, name: form.translations[lang.code] || '' })),
    };
    if (tab.value === 'categories') {
        Object.assign(body, { key: form.key, emoji: form.emoji || null, color: form.color || null });
    } else {
        Object.assign(body, { country_id: form.country_id, timezone: form.timezone, lat: form.lat === '' ? null : form.lat, lng: form.lng === '' ? null : form.lng });
    }

    try {
        if (editingId.value) {
            await adminAxios.put(`${endpoint.value}/${editingId.value}`, body);
        } else {
            await adminAxios.post(endpoint.value, body);
        }
        showSuccess(t('discover.catalog.saved'));
        showModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { errors[key.startsWith('translations') ? 'translations' : key] = messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error);
        const missing = languages.value.find((lang) => !(form.translations[lang.code] || '').trim());
        if (errors.translations && missing) activeLocale.value = missing.code;
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(row) {
    try {
        await adminAxios.patch(`${endpoint.value}/${row.id}/status`, { status: !row.status });
        row.status = !row.status;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(row) {
    if (!window.confirm(t('discover.catalog.confirm_delete', { name: row.name || `#${row.id}` }))) return;
    try {
        await adminAxios.delete(`${endpoint.value}/${row.id}`);
        showSuccess(t('chat.common.deleted'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;
    rows.value = [];
    try {
        const { data } = await adminAxios.get(endpoint.value);
        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

async function loadCountries() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/countries/dropdown');
        countries.value = data?.data ?? [];
    } catch {
        countries.value = [];
    }
}

watch(languages, () => ensureTranslations(), { immediate: true });
watch(tab, () => load());

onMounted(async () => {
    load();
    loadCountries();
    await languagesStore.fetch();
});
</script>
