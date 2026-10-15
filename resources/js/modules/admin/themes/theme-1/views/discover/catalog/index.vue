<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('discover.catalog.title') }}
                <span v-if="!loading" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ rows.length }}</span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item">{{ t('sidebar.discover') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('discover.catalog.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="search" type="search" class="form-control" :placeholder="t('search_placeholder')">
                        <button v-if="search" type="button" class="btn btn-light border catalog-search-clear" :title="t('countries.clear_search')" @click="search = ''">
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                    <button v-if="statusFilter !== 'all'" type="button" class="btn btn-sm catalog-filter-btn catalog-filter-btn--all-idle" @click="statusFilter = 'all'">
                        {{ t('countries.filter_all') }} ({{ counts.total }})
                    </button>
                    <button type="button" class="btn btn-sm catalog-filter-btn" :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'" @click="statusFilter = 'active'">
                        {{ t('countries.filter_active') }} ({{ counts.active }})
                    </button>
                    <button type="button" class="btn btn-sm catalog-filter-btn" :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'" @click="statusFilter = 'inactive'">
                        {{ t('countries.filter_inactive') }} ({{ counts.inactive }})
                    </button>
                </div>
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <button v-if="canMultipleDelete && selectedCount" type="button" class="btn btn-danger btn-sm btn-wave" @click="confirmDeleteSelected">
                        <i class="ri-delete-bin-line me-1 align-middle"></i>{{ t('catalog.bulk_delete_count', { count: selectedCount }) }}
                    </button>
                    <button
                        v-if="canUpdate"
                        type="button"
                        class="btn btn-sm btn-wave"
                        :class="reorderMode ? 'btn-primary' : 'btn-outline-primary'"
                        @click="toggleReorderMode"
                    >
                        <i class="ri-drag-move-2-line me-1 align-middle"></i>
                        {{ reorderMode ? t('service_categories.reorder_done') : t('service_categories.reorder') }}
                    </button>
                    <button v-if="canCreate && !reorderMode" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                        <i class="ri-add-line me-1 align-middle"></i>{{ t('discover.catalog.add_category') }}
                    </button>
                </div>
            </div>

            <div v-if="reorderMode" class="card-body p-4">
                <DiscoverReorderPanel ref="reorderPanelRef" kind="categories" :can-update="canUpdate" />
            </div>

            <div v-else class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th v-if="canMultipleDelete" scope="col" class="ps-4" style="width: 48px;">
                                    <input class="form-check-input" type="checkbox" :checked="allSelected" :disabled="loading || !pagedRows.length" @change="onSelectAll($event.target.checked)">
                                </th>
                                <th :class="{ 'ps-4': !canMultipleDelete }">{{ t('chat.common.name') }}</th>
                                <th>{{ t('discover.catalog.key') }}</th>
                                <th>{{ t('discover.catalog.events') }}</th>
                                <th>{{ t('countries.created_at') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="skeletonLayout" />
                            <tr v-else-if="!pagedRows.length">
                                <td :colspan="canMultipleDelete ? 7 : 6" class="border-0">
                                    <div class="text-center py-5">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3"><i class="ri-price-tag-3-line fs-2 text-primary"></i></span>
                                        <p class="text-muted mb-3">{{ t('wallet.common.empty') }}</p>
                                        <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                            <i class="ri-add-line me-1 align-middle"></i>{{ t('discover.catalog.add_category') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in pagedRows" :key="row.id" class="crm-contact">
                                    <td v-if="canMultipleDelete" class="ps-4">
                                        <input class="form-check-input" type="checkbox" :checked="isSelected(row.id)" @change="onRowSelect(row.id, $event.target.checked)">
                                    </td>
                                    <td :class="{ 'ps-4': !canMultipleDelete }">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm avatar-rounded" :style="{ background: row.color || '#eef' }"><span class="fs-15">{{ row.emoji || '📍' }}</span></span>
                                            <div>
                                                <span class="fw-semibold d-block">{{ row.name || `#${row.id}` }}</span>
                                                <span class="d-block text-muted fs-11">#{{ row.id }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><code>{{ row.key }}</code></td>
                                    <td><span class="badge bg-info-transparent">{{ row.events_count }}</span></td>
                                    <td>
                                        <span class="d-block">{{ formatCatalogDate(row.created_at, locale) }}</span>
                                        <span v-if="row.updated_at" class="d-block text-muted fs-11">{{ t('countries.updated') }}: {{ formatCatalogDate(row.updated_at, locale) }}</span>
                                    </td>
                                    <td>
                                        <div
                                            v-if="canUpdate"
                                            class="toggle toggle-success mb-0 catalog-status-toggle"
                                            :class="{ on: row.status, 'catalog-status-toggle--loading': toggling.includes(row.id) }"
                                            role="button"
                                            tabindex="0"
                                            :aria-busy="toggling.includes(row.id)"
                                            @click="toggleStatus(row)"
                                            @keydown.enter.space.prevent="toggleStatus(row)"
                                        ><span></span></div>
                                        <span v-else class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ row.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('countries.edit_title')" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                            <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('countries.confirm_delete')" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="!reorderMode && !loading && filteredRows.length" class="card-footer border-top-0">
                <div class="d-flex align-items-center flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-2 text-muted fs-13">
                        <span>{{ entriesLabel }}</span>
                        <i :class="locale === 'ar' ? 'ri-arrow-left-s-line fw-semibold' : 'ri-arrow-right-s-line fw-semibold'"></i>
                    </div>
                    <div class="d-flex align-items-center gap-2 ms-md-auto">
                        <label class="text-muted fs-13 mb-0" for="discover-per-page">{{ t('countries.per_page') }}</label>
                        <select id="discover-per-page" v-model.number="perPage" class="form-select form-select-sm w-auto">
                            <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
                        </select>
                    </div>
                    <nav v-if="lastPage > 1" aria-label="Pagination" class="pagination-style-4">
                        <ul class="pagination mb-0">
                            <li class="page-item" :class="{ disabled: page <= 1 }">
                                <button type="button" class="page-link" @click="page -= 1">{{ t('countries.previous') }}</button>
                            </li>
                            <li v-for="n in pageNumbers" :key="n" class="page-item" :class="{ active: n === page }">
                                <button type="button" class="page-link" @click="page = n">{{ n }}</button>
                            </li>
                            <li class="page-item" :class="{ disabled: page >= lastPage }">
                                <button type="button" class="page-link text-primary" @click="page += 1">{{ t('countries.next') }}</button>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>

        <ConfirmDeleteModal
            :show="deleteConfirm.state.show"
            :title="deleteConfirm.state.title"
            :message="deleteConfirm.state.message"
            :loading="deleteConfirm.state.loading"
            @close="deleteConfirm.close()"
            @confirm="confirmRemove"
        />

        <WalletModal :show="showModal" :title="editingId ? t('discover.catalog.edit_category') : t('discover.catalog.add_category')" size="md" @close="showModal = false">
            <form id="discover-catalog-form" novalidate @submit.prevent="save">
                <CatalogTranslationTabs :languages="languages" :active-locale="activeLocale" :translation-tab-class="tabClass" :translation-tab-feedback="tabFeedback" @update:active-locale="activeLocale = $event" />
                <div class="mb-3">
                    <label class="form-label">{{ t('chat.common.name') }} <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-price-tag-3-line"></i></span>
                        <input v-model="form.translations[activeLocale]" type="text" class="form-control" :class="{ 'is-invalid': !!transError(activeLocale) }">
                    </div>
                    <div v-if="transError(activeLocale)" class="text-danger fs-12 mt-1">{{ transError(activeLocale) }}</div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-12">
                        <label class="form-label">{{ t('discover.catalog.key') }} <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="ri-key-2-line"></i></span>
                            <input v-model="form.key" type="text" dir="ltr" class="form-control" :class="{ 'is-invalid': !!fieldError('key') }" :placeholder="'concerts'">
                        </div>
                        <div v-if="fieldError('key')" class="text-danger fs-12 mt-1">{{ fieldError('key') }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t('discover.catalog.emoji') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-emotion-line"></i></span>
                                <input v-model="form.emoji" type="text" class="form-control text-center" :class="{ 'is-invalid': !!fieldError('emoji') }">
                            </div>
                        <div v-if="fieldError('emoji')" class="text-danger fs-12 mt-1">{{ fieldError('emoji') }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t('discover.catalog.color') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-palette-line"></i></span>
                                <input v-model="form.color" type="color" class="form-control form-control-color w-100" :class="{ 'is-invalid': !!fieldError('color') }">
                            </div>
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label d-block mb-2">{{ t('wallet.common.status') }}</label>
                        <div
                            class="toggle toggle-success mb-0 catalog-modal-toggle"
                            :class="{ on: form.status }"
                            role="button"
                            tabindex="0"
                            @click="form.status = !form.status"
                            @keydown.enter.space.prevent="form.status = !form.status"
                        ><span></span></div>
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
import useVuelidate from '@vuelidate/core';
import { helpers } from '@vuelidate/validators';
import adminAxios from '../../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../../components/catalog/CatalogTranslationTabs.vue';
import DiscoverReorderPanel from '../../../../../../../components/catalog/DiscoverReorderPanel.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import ConfirmDeleteModal from '../../../../../../../components/ui/ConfirmDeleteModal.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { useConfirmDelete } from '../../../../../../../composables/useConfirmDelete';
import useValidation from '../../../../../../../composables/useValidation';
import { usePermission } from '../../../../../../../composables/usePermission';
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';
import { formatCatalogDate } from '../../../../../../../utils/catalog';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const languagesStore = useAvailableLanguagesStore();
const deleteConfirm = useConfirmDelete();

const reorderMode = ref(false);
const reorderPanelRef = ref(null);

function toggleReorderMode() {
    reorderMode.value = !reorderMode.value;
    if (!reorderMode.value) load();
}

const endpoint = '/api/admin/v1/discover-categories';
const canCreate = computed(() => can('discover-categories.create'));
const canUpdate = computed(() => can('discover-categories.update'));
const canDelete = computed(() => can('discover-categories.delete'));

const languages = computed(() => languagesStore.items);
const activeLocale = ref('');
const rows = ref([]);
const loading = ref(false);

const search = ref('');
const statusFilter = ref('all');
const page = ref(1);
const perPage = ref(15);
const perPageOptions = [15, 25, 50];

const counts = computed(() => ({
    total: rows.value.length,
    active: rows.value.filter((r) => r.status).length,
    inactive: rows.value.filter((r) => !r.status).length,
}));
const filteredRows = computed(() => {
    const q = search.value.trim().toLowerCase();

    return rows.value.filter((r) => (statusFilter.value === 'all' || (statusFilter.value === 'active') === !!r.status)
        && (!q || `${r.name || ''} ${r.key || ''}`.toLowerCase().includes(q)));
});
const lastPage = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / perPage.value)));
const pagedRows = computed(() => filteredRows.value.slice((page.value - 1) * perPage.value, page.value * perPage.value));
const pageNumbers = computed(() => {
    const start = Math.max(1, Math.min(page.value - 2, lastPage.value - 4));
    const end = Math.min(lastPage.value, start + 4);

    return Array.from({ length: end - start + 1 }, (_, i) => start + i);
});
const entriesLabel = computed(() => t('countries.showing_entries', {
    from: filteredRows.value.length ? (page.value - 1) * perPage.value + 1 : 0,
    to: Math.min(page.value * perPage.value, filteredRows.value.length),
    total: filteredRows.value.length,
}));
watch([search, statusFilter, perPage], () => { page.value = 1; });
watch([page, perPage, search, statusFilter], () => { selectedIds.value = []; });
watch(lastPage, (last) => { if (page.value > last) page.value = last; });

const showModal = ref(false);
const editingId = ref(null);
const saving = ref(false);
const errors = reactive({});
const blank = () => ({ key: '', emoji: '', color: '#2E86C1', status: true, translations: {} });
const form = reactive(blank());

const { requiredField, maxString } = useValidation();
const regexRule = (labelKey, pattern) => helpers.withMessage(() => t('validation.regex', { field: t(labelKey) }), helpers.regex(pattern));
const nameRules = () => ({ required: requiredField('validation.attributes.name'), maxLength: maxString('validation.attributes.name', 80) });
const rules = computed(() => ({
    key: {
        required: requiredField('discover.catalog.key'),
        maxLength: maxString('discover.catalog.key', 40),
        format: helpers.withMessage(() => t('discover.validation.key_format'), helpers.regex(/^[a-z0-9_]+$/)),
    },
    emoji: { maxLength: maxString('discover.catalog.emoji', 16) },
    color: { format: regexRule('discover.catalog.color', /^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/) },
    translations: Object.fromEntries(languages.value.map((lang) => [lang.code, nameRules()])),
}));
const v$ = useVuelidate(rules, form, { $autoDirty: true });

/** The first Vuelidate message of a field once it was touched, else what the server said about it. */
function fieldError(name) {
    const field = v$.value[name];

    if (field?.$dirty && field.$invalid) return field.$errors[0]?.$message || '';

    return errors[name] || '';
}

/** Same for the name of one language tab. */
function transError(code) {
    const field = v$.value.translations?.[code];

    if (field?.$dirty && field.$invalid) return field.$errors[0]?.$message || '';

    return errors.translations && !(form.translations[code] || '').trim() ? errors.translations : '';
}

function firstInvalidLocale() {
    return languages.value.find((lang) => v$.value.translations?.[lang.code]?.$invalid)?.code;
}


function tabClass(code) {
    const filled = !!(form.translations[code] || '').trim();

    return { active: activeLocale.value === code, 'catalog-lang-tab--error': !!transError(code), 'catalog-lang-tab--valid': filled };
}

function tabFeedback(code) {
    const filled = !!(form.translations[code] || '').trim();

    return { show: filled || !!transError(code), valid: filled && !transError(code) };
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
    v$.value.$reset();
    Object.assign(form, blank());
    ensureTranslations();
    activeLocale.value = languages.value[0]?.code || '';
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    resetErrors();
    v$.value.$reset();
    Object.assign(form, blank(), {
        key: row.key ?? '', emoji: row.emoji ?? '', color: row.color || '#2E86C1', status: !!row.status,
    });
    ensureTranslations();
    (row.translations ?? []).forEach((tr) => { form.translations[tr.locale] = tr.name ?? ''; });
    activeLocale.value = languages.value[0]?.code || '';
    showModal.value = true;
}

async function save() {
    resetErrors();
    if (!(await v$.value.$validate())) {
        activeLocale.value = firstInvalidLocale() || activeLocale.value;

        return;
    }
    saving.value = true;
    const body = {
        status: form.status,
        translations: languages.value.map((lang) => ({ locale: lang.code, name: form.translations[lang.code] || '' })),
        key: form.key,
        emoji: form.emoji || null,
        color: form.color || null,
    };

    try {
        if (editingId.value) {
            await adminAxios.put(`${endpoint}/${editingId.value}`, body);
        } else {
            await adminAxios.post(endpoint, body);
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

const toggling = ref([]);

async function toggleStatus(row) {
    if (toggling.value.includes(row.id)) return;
    toggling.value.push(row.id);
    try {
        await adminAxios.patch(`${endpoint}/${row.id}/status`, { status: !row.status });
        row.status = !row.status;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        toggling.value = toggling.value.filter((id) => id !== row.id);
    }
}


const skeletonLayout = computed(() => [...(canMultipleDelete.value ? ['check'] : []), 'avatar-text', 'badge', 'badge', 'two-lines', 'toggle', 'actions']);
const canMultipleDelete = computed(() => can('discover-categories.multiple-delete'));
const selectedIds = ref([]);
const selectedCount = computed(() => selectedIds.value.length);
const allSelected = computed(() => pagedRows.value.length > 0 && pagedRows.value.every((r) => selectedIds.value.includes(r.id)));
const isSelected = (id) => selectedIds.value.includes(id);
function onSelectAll(checked) {
    selectedIds.value = checked ? pagedRows.value.map((r) => r.id) : [];
}
function onRowSelect(id, checked) {
    selectedIds.value = checked ? [...new Set([...selectedIds.value, id])] : selectedIds.value.filter((x) => x !== id);
}

function confirmDeleteSelected() {
    deleteConfirm.open({ title: t('countries.delete_selected_title'), message: t('countries.confirm_delete_selected'), payload: { bulk: true } });
}

async function deleteSelected() {
    deleteConfirm.setLoading(true);
    try {
        const { data } = await adminAxios.post('/api/admin/v1/discover-categories/delete-multiple', { ids: selectedIds.value });
        const result = data?.data ?? {};
        showSuccess(result.skipped ? t('discover.bulk.result', { deleted: result.deleted ?? 0, skipped: result.skipped }) : t('chat.common.deleted'));
        selectedIds.value = [];
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
        load();
    } catch (error) {
        deleteConfirm.setLoading(false);
        showError(extractApiErrorMessage(error));
    }
}

function remove(row) {
    deleteConfirm.open({
        title: t('countries.confirm_delete'),
        message: t('discover.catalog.confirm_delete', { name: row.name || `#${row.id}` }),
        payload: row,
    });
}

async function confirmRemove() {
    const row = deleteConfirm.state.payload;
    if (!row) return;
    if (row.bulk) {
        await deleteSelected();
        return;
    }
    deleteConfirm.setLoading(true);
    try {
        await adminAxios.delete(`${endpoint}/${row.id}`);
        showSuccess(t('chat.common.deleted'));
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
        load();
    } catch (error) {
        deleteConfirm.setLoading(false);
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(endpoint);
        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

watch(languages, () => ensureTranslations(), { immediate: true });

onMounted(async () => {
    load();
    await languagesStore.fetch();
});
</script>
