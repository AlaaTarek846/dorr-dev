<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('freelance.skills.title') }}
                <span v-if="!loading" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ pagination.total ?? 0 }}</span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.skills.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border mb-3 fs-13">{{ t('freelance.skills.hint') }}</div>

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
                    <Select
                        v-model="specialtyFilter"
                        :options="specialtyFilterOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('freelance.skills.all_specialties')"
                        filter
                        :filter-placeholder="t('search_placeholder')"
                        show-clear
                        class="wallet-filter-select"
                    />
                    <Select
                        v-model="statusFilter"
                        :options="statusFilterOptions"
                        option-label="label"
                        option-value="value"
                        class="wallet-filter-select"
                    />
                </div>
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <button v-if="canMultipleDelete && selectedCount" type="button" class="btn btn-danger btn-sm btn-wave" @click="confirmDeleteSelected">
                        <i class="ri-delete-bin-line me-1 align-middle"></i>{{ t('catalog.bulk_delete_count', { count: selectedCount }) }}
                    </button>
                    <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                        <i class="ri-add-line me-1 align-middle"></i>{{ t('freelance.skills.add') }}
                    </button>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th v-if="canMultipleDelete" scope="col" class="ps-4" style="width: 48px;">
                                    <input class="form-check-input" type="checkbox" :checked="allSelected" :disabled="loading || !rows.length" @change="onSelectAll($event.target.checked)">
                                </th>
                                <th :class="{ 'ps-4': !canMultipleDelete }">{{ t('chat.common.name') }}</th>
                                <th>{{ t('freelance.skills.specialties') }}</th>
                                <th>{{ t('freelance.skills.usage') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="skeletonLayout" />
                            <tr v-else-if="!rows.length">
                                <td :colspan="canMultipleDelete ? 6 : 5" class="border-0">
                                    <div class="text-center py-5">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3"><i class="ri-medal-line fs-2 text-primary"></i></span>
                                        <p class="text-muted mb-3">{{ t('wallet.common.empty') }}</p>
                                    </div>
                                </td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" class="crm-contact">
                                    <td v-if="canMultipleDelete" class="ps-4">
                                        <input class="form-check-input" type="checkbox" :checked="isSelected(row.id)" @change="onRowSelect(row.id, $event.target.checked)">
                                    </td>
                                    <td :class="{ 'ps-4': !canMultipleDelete }">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent"><i class="ri-medal-line"></i></span>
                                            <div>
                                                <span class="fw-semibold d-block">{{ row.name || `#${row.id}` }}</span>
                                                <code class="d-block text-muted fs-11">{{ row.slug }}</code>
                                                <span v-if="row.merged_into" class="d-block text-muted fs-11"><i class="ri-git-merge-line me-1"></i>{{ t('freelance.skills.merged_into', { name: row.merged_into.name }) }}</span>
                                                <span v-if="row.status === 'rejected' && row.reject_reason" class="d-block text-danger fs-11">{{ row.reject_reason }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="max-width: 260px; white-space: normal;">
                                        <span v-for="sp in row.specialties" :key="sp.id" class="badge bg-info-transparent me-1 mb-1">{{ sp.name }}</span>
                                        <span v-if="!row.specialties.length" class="text-muted">—</span>
                                    </td>
                                    <td><span class="badge bg-light text-dark border">{{ row.usage_count }}</span></td>
                                    <td>
                                        <div
                                            v-if="canUpdate && ['active', 'inactive'].includes(row.status)"
                                            class="toggle toggle-success mb-0 catalog-status-toggle"
                                            :class="{ on: row.status === 'active', 'catalog-status-toggle--loading': toggling.includes(row.id) }"
                                            role="button"
                                            tabindex="0"
                                            :aria-busy="toggling.includes(row.id)"
                                            @click="toggleStatus(row)"
                                            @keydown.enter.space.prevent="toggleStatus(row)"
                                        ><span></span></div>
                                        <span v-else class="badge" :class="statusBadge(row.status)">{{ t(`freelance.skills.status.${row.status}`) }}</span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <template v-if="row.status === 'pending' && canUpdate">
                                                <button type="button" class="btn btn-sm btn-success-light btn-icon" :title="t('freelance.skills.approve')" @click="openApprove(row)"><i class="ri-check-line"></i></button>
                                                <button type="button" class="btn btn-sm btn-warning-light btn-icon" :title="t('freelance.skills.reject')" @click="openReject(row)"><i class="ri-close-line"></i></button>
                                            </template>
                                            <button v-if="canUpdate && ['pending', 'active', 'inactive'].includes(row.status)" type="button" class="btn btn-sm btn-secondary-light btn-icon" :title="t('freelance.skills.merge')" @click="openMerge(row)"><i class="ri-git-merge-line"></i></button>
                                            <button v-if="canUpdate && ['active', 'inactive'].includes(row.status)" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('countries.edit_title')" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                            <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('countries.confirm_delete')" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="!loading && rows.length" class="card-footer border-top-0">
                <div class="d-flex align-items-center flex-wrap gap-3">
                    <div class="text-muted fs-13">{{ t('countries.showing_entries', { from: pagination.from ?? 0, to: pagination.to ?? 0, total: pagination.total ?? 0 }) }}</div>
                    <div class="d-flex align-items-center gap-2 ms-md-auto">
                        <label class="text-muted fs-13 mb-0" for="skills-per-page">{{ t('countries.per_page') }}</label>
                        <select id="skills-per-page" v-model.number="perPage" class="form-select form-select-sm w-auto">
                            <option v-for="n in [15, 25, 50]" :key="n" :value="n">{{ n }}</option>
                        </select>
                    </div>
                    <nav v-if="(pagination.last_page ?? 1) > 1" aria-label="Pagination" class="pagination-style-4">
                        <ul class="pagination mb-0">
                            <li class="page-item" :class="{ disabled: page <= 1 }"><button type="button" class="page-link" @click="page -= 1">{{ t('countries.previous') }}</button></li>
                            <li v-for="n in pageNumbers" :key="n" class="page-item" :class="{ active: n === page }"><button type="button" class="page-link" @click="page = n">{{ n }}</button></li>
                            <li class="page-item" :class="{ disabled: page >= pagination.last_page }"><button type="button" class="page-link text-primary" @click="page += 1">{{ t('countries.next') }}</button></li>
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

        <!-- Add, edit, or approve a suggestion (the admin completes its names and specialties). -->
        <WalletModal :show="showModal" :title="modalTitle" size="md" @close="showModal = false">
            <form id="freelance-skill-form" novalidate @submit.prevent="save">
                <div v-if="mode === 'approve'" class="alert alert-light border fs-13 mb-3">{{ t('freelance.skills.approve_hint') }}</div>
                <CatalogTranslationTabs :languages="languages" :active-locale="activeLocale" :translation-tab-class="tabClass" :translation-tab-feedback="tabFeedback" @update:active-locale="activeLocale = $event" />
                <div class="mb-3">
                    <label class="form-label">{{ t('chat.common.name') }} <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-medal-line"></i></span>
                        <input v-model="form.translations[activeLocale]" type="text" class="form-control" :class="{ 'is-invalid': !!transError(activeLocale) }">
                    </div>
                    <div v-if="transError(activeLocale)" class="text-danger fs-12 mt-1">{{ transError(activeLocale) }}</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ t('freelance.skills.specialties') }}</label>
                    <MultiSelect
                        v-model="form.specialty_ids"
                        :options="specialtyOptions"
                        option-label="label"
                        option-value="value"
                        display="chip"
                        filter
                        :filter-placeholder="t('search_placeholder')"
                        :placeholder="t('freelance.skills.pick_specialties')"
                        append-to="body"
                        class="w-100"
                    />
                </div>
                <div v-if="mode !== 'approve'" class="mb-1">
                    <label class="form-label d-block mb-2">{{ t('wallet.common.status') }}</label>
                    <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.active }" role="button" tabindex="0" @click="form.active = !form.active" @keydown.enter.space.prevent="form.active = !form.active"><span></span></div>
                </div>
                <div v-if="errors.general" class="text-danger mt-2 fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="freelance-skill-form" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ mode === 'approve' ? t('freelance.skills.approve') : t('save') }}</button>
            </template>
        </WalletModal>

        <WalletModal :show="!!rejecting" :title="t('freelance.skills.reject')" size="md" @close="rejecting = null">
            <form v-if="rejecting" id="freelance-skill-reject-form" novalidate @submit.prevent="reject">
                <p class="fw-semibold mb-2">{{ rejecting.name }}</p>
                <label class="form-label">{{ t('freelance.skills.reject_reason') }}</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="ri-chat-1-line"></i></span>
                    <input v-model="rejectReason" type="text" maxlength="255" class="form-control" :placeholder="t('freelance.skills.reject_reason_hint')">
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="rejecting = null">{{ t('cancel') }}</button>
                <button type="submit" form="freelance-skill-reject-form" class="btn btn-warning" :disabled="saving">{{ t('freelance.skills.reject') }}</button>
            </template>
        </WalletModal>

        <WalletModal :show="!!merging" :title="t('freelance.skills.merge')" size="md" @close="merging = null">
            <form v-if="merging" id="freelance-skill-merge-form" novalidate @submit.prevent="merge">
                <div class="alert alert-light border fs-13 mb-3">{{ t('freelance.skills.merge_hint', { name: merging.name, usage: merging.usage_count }) }}</div>
                <label class="form-label">{{ t('freelance.skills.merge_into') }} <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="ri-git-merge-line"></i></span>
                    <Select v-model="mergeTarget" :options="mergeOptions" option-label="label" option-value="value" filter :filter-placeholder="t('search_placeholder')" append-to="body" class="flex-grow-1" @filter="onMergeFilter" />
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="merging = null">{{ t('cancel') }}</button>
                <button type="submit" form="freelance-skill-merge-form" class="btn btn-primary" :disabled="!mergeTarget || saving">{{ t('freelance.skills.merge') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MultiSelect from 'primevue/multiselect';
import Select from 'primevue/select';
import useVuelidate from '@vuelidate/core';
import adminAxios from '../../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../../components/catalog/CatalogTranslationTabs.vue';
import ConfirmDeleteModal from '../../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { useConfirmDelete } from '../../../../../../../composables/useConfirmDelete';
import { usePermission } from '../../../../../../../composables/usePermission';
import useValidation from '../../../../../../../composables/useValidation';
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const { requiredField, maxString } = useValidation();
const languagesStore = useAvailableLanguagesStore();
const deleteConfirm = useConfirmDelete();

const endpoint = '/api/admin/v1/freelance-skills';
const canCreate = computed(() => can('freelance-catalogs.create'));
const canUpdate = computed(() => can('freelance-catalogs.update'));
const canDelete = computed(() => can('freelance-catalogs.delete'));
const canMultipleDelete = computed(() => can('freelance-catalogs.multiple-delete'));

const languages = computed(() => languagesStore.items);
const activeLocale = ref('');
const rows = ref([]);
const pagination = ref({});
const counts = ref({});
const loading = ref(false);
const toggling = ref([]);
const specialties = ref([]);

// ---- list: search, filters and pages (on the server — the catalog can be large)
const search = ref('');
const statusFilter = ref('all');
const specialtyFilter = ref(null);
const page = ref(1);
const perPage = ref(15);
const statusTabs = ['pending', 'active', 'inactive', 'rejected', 'merged'];
const total = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0));
const statusFilterOptions = computed(() => [
    { value: 'all', label: `${t('countries.filter_all')} (${total.value})` },
    ...statusTabs.map((s) => ({ value: s, label: `${t(`freelance.skills.status.${s}`)} (${counts.value[s] ?? 0})` })),
]);
const specialtyOptions = computed(() => specialties.value.map((s) => ({ value: s.id, label: s.parent ? `${s.parent} › ${s.name}` : s.name })));
const specialtyFilterOptions = specialtyOptions;
const pageNumbers = computed(() => {
    const last = pagination.value.last_page ?? 1;
    const start = Math.max(1, Math.min(page.value - 2, last - 4));
    const end = Math.min(last, start + 4);

    return Array.from({ length: end - start + 1 }, (_, i) => start + i);
});
const statusBadge = (s) => ({ pending: 'bg-warning-transparent', rejected: 'bg-danger-transparent', merged: 'bg-secondary-transparent', active: 'bg-success-transparent', inactive: 'bg-secondary-transparent' }[s]);

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { page.value === 1 ? load() : (page.value = 1); }, 300);
});
watch([statusFilter, specialtyFilter, perPage], () => { page.value === 1 ? load() : (page.value = 1); });
watch(page, load);

async function load() {
    loading.value = true;
    selectedIds.value = [];
    try {
        const { data } = await adminAxios.get(endpoint, {
            params: { page: page.value, per_page: perPage.value, search: search.value || undefined, status: statusFilter.value === 'all' ? undefined : statusFilter.value, specialty_id: specialtyFilter.value || undefined },
        });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
        counts.value = data.pagination?.counts ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

// ---- selection and bulk delete
const skeletonLayout = computed(() => [...(canMultipleDelete.value ? ['check'] : []), 'avatar-text', 'text', 'badge', 'toggle', 'actions']);
const selectedIds = ref([]);
const selectedCount = computed(() => selectedIds.value.length);
const allSelected = computed(() => rows.value.length > 0 && rows.value.every((r) => selectedIds.value.includes(r.id)));
const isSelected = (id) => selectedIds.value.includes(id);
const onSelectAll = (checked) => { selectedIds.value = checked ? rows.value.map((r) => r.id) : []; };
const onRowSelect = (id, checked) => { selectedIds.value = checked ? [...new Set([...selectedIds.value, id])] : selectedIds.value.filter((x) => x !== id); };
const confirmDeleteSelected = () => deleteConfirm.open({ title: t('countries.delete_selected_title'), message: t('countries.confirm_delete_selected'), payload: { bulk: true } });

// ---- the form: add, edit, approve
const showModal = ref(false);
const mode = ref('create');
const editingId = ref(null);
const saving = ref(false);
const errors = reactive({});
const blank = () => ({ translations: {}, specialty_ids: [], active: true });
const form = reactive(blank());
const modalTitle = computed(() => ({ create: t('freelance.skills.add'), edit: t('freelance.skills.edit'), approve: t('freelance.skills.approve') }[mode.value]));

const rules = computed(() => ({
    translations: Object.fromEntries(languages.value.map((lang) => [lang.code, { required: requiredField('validation.attributes.name'), maxLength: maxString('validation.attributes.name', 80) }])),
}));
const v$ = useVuelidate(rules, form, { $autoDirty: true });

function transError(code) {
    const field = v$.value.translations?.[code];

    if (field?.$dirty && field.$invalid) return field.$errors[0]?.$message || '';

    return errors.translations && !(form.translations[code] || '').trim() ? errors.translations : '';
}
const tabClass = (code) => ({ active: activeLocale.value === code, 'catalog-lang-tab--error': !!transError(code), 'catalog-lang-tab--valid': !!(form.translations[code] || '').trim() });
const tabFeedback = (code) => {
    const filled = !!(form.translations[code] || '').trim();

    return { show: filled || !!transError(code), valid: filled && !transError(code) };
};

function ensureTranslations() {
    languages.value.forEach((lang) => { form.translations[lang.code] ??= ''; });
    if (!activeLocale.value && languages.value.length) activeLocale.value = languages.value[0].code;
}

function openForm(newMode, row = null) {
    mode.value = newMode;
    editingId.value = row?.id ?? null;
    Object.keys(errors).forEach((key) => delete errors[key]);
    v$.value.$reset();
    Object.assign(form, blank(), row ? { specialty_ids: row.specialties.map((s) => s.id), active: row.status === 'active' } : {});
    ensureTranslations();
    (row?.translations ?? []).forEach((tr) => { form.translations[tr.locale] = tr.name ?? ''; });
    // Open on the language that still needs a name (a suggestion has one only).
    activeLocale.value = languages.value.find((lang) => !(form.translations[lang.code] || '').trim())?.code || languages.value[0]?.code || '';
    showModal.value = true;
}
const openCreate = () => openForm('create');
const openEdit = (row) => openForm('edit', row);
const openApprove = (row) => openForm('approve', row);

async function save() {
    Object.keys(errors).forEach((key) => delete errors[key]);
    if (!(await v$.value.$validate())) {
        activeLocale.value = languages.value.find((lang) => v$.value.translations?.[lang.code]?.$invalid)?.code || activeLocale.value;

        return;
    }
    saving.value = true;
    const body = {
        translations: languages.value.map((lang) => ({ locale: lang.code, name: form.translations[lang.code] || '' })),
        specialty_ids: form.specialty_ids,
    };
    if (mode.value !== 'approve') body.status = form.active ? 'active' : 'inactive';

    try {
        const response = mode.value === 'approve'
            ? await adminAxios.post(`${endpoint}/${editingId.value}/approve`, body)
            : editingId.value ? await adminAxios.put(`${endpoint}/${editingId.value}`, body) : await adminAxios.post(endpoint, body);
        showSuccess(extractApiMessage(response, t('toast.success')));
        showModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { errors[key.startsWith('translations') ? 'translations' : key] = messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error, t('toast.error'));
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(row) {
    if (toggling.value.includes(row.id)) return;
    toggling.value.push(row.id);
    try {
        const response = await adminAxios.patch(`${endpoint}/${row.id}/status`, { status: row.status !== 'active' });
        showSuccess(extractApiMessage(response, t('toast.success')));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        toggling.value = toggling.value.filter((id) => id !== row.id);
    }
}

// ---- reject
const rejecting = ref(null);
const rejectReason = ref('');
function openReject(row) {
    rejecting.value = row;
    rejectReason.value = '';
}
async function reject() {
    saving.value = true;
    try {
        const response = await adminAxios.post(`${endpoint}/${rejecting.value.id}/reject`, { reason: rejectReason.value || null });
        showSuccess(extractApiMessage(response, t('toast.success')));
        rejecting.value = null;
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

// ---- merge a duplicate into another skill (the target is searched on the server)
const merging = ref(null);
const mergeTarget = ref(null);
const mergeOptions = ref([]);
let mergeTimer = null;

async function searchTargets(term = '') {
    try {
        const { data } = await adminAxios.get(endpoint, { params: { status: 'active', search: term || undefined, per_page: 20 } });
        mergeOptions.value = (data.data ?? []).filter((s) => s.id !== merging.value?.id).map((s) => ({ value: s.id, label: s.name }));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}
function onMergeFilter(event) {
    clearTimeout(mergeTimer);
    mergeTimer = setTimeout(() => searchTargets(event.value), 250);
}
function openMerge(row) {
    merging.value = row;
    mergeTarget.value = null;
    mergeOptions.value = [];
    searchTargets();
}
async function merge() {
    saving.value = true;
    try {
        const response = await adminAxios.post(`${endpoint}/${merging.value.id}/merge`, { target_id: mergeTarget.value });
        showSuccess(extractApiMessage(response, t('toast.success')));
        merging.value = null;
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

// ---- delete
function remove(row) {
    deleteConfirm.open({ title: t('countries.confirm_delete'), message: t('freelance.skills.confirm_delete', { name: row.name || `#${row.id}` }), payload: row });
}

async function confirmRemove() {
    const row = deleteConfirm.state.payload;
    if (!row) return;
    deleteConfirm.setLoading(true);
    try {
        let response;
        if (row.bulk) {
            response = await adminAxios.post(`${endpoint}/delete-multiple`, { ids: selectedIds.value });
            const result = response.data?.data ?? {};
            showSuccess(result.skipped ? t('freelance.skills.bulk_result', { deleted: result.deleted ?? 0, skipped: result.skipped }) : extractApiMessage(response, t('toast.success')));
        } else {
            response = await adminAxios.delete(`${endpoint}/${row.id}`);
            showSuccess(extractApiMessage(response, t('toast.success')));
        }
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
        load();
    } catch (error) {
        deleteConfirm.setLoading(false);
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

watch(languages, () => ensureTranslations(), { immediate: true });

onMounted(async () => {
    load();
    try {
        const { data } = await adminAxios.get(`${endpoint}/options`);
        specialties.value = data.data?.specialties ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
    await languagesStore.fetch();
});
</script>
