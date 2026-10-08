<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('support.quick.title') }}
                <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">
                    {{ pagination.total }}
                </span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('support.quick.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                        <div class="d-flex flex-wrap align-items-center gap-1 catalog-toolbar-filters">
                            <div class="input-group input-group-sm catalog-toolbar-search">
                                <span class="input-group-text bg-white">
                                    <i class="ri-search-line text-muted"></i>
                                </span>
                                <input v-model="search" type="search" class="form-control" :placeholder="t('support.quick.search')">
                                <button v-if="search" type="button" class="btn btn-light border catalog-search-clear" :title="t('support.quick.clear_search')" @click="search = ''">
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button
                                v-if="statusFilter !== 'all'"
                                type="button"
                                class="btn btn-sm catalog-filter-btn catalog-filter-btn--all-idle"
                                @click="setStatusFilter('all')"
                            >
                                {{ t('support.quick.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('support.quick.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('support.quick.filter_inactive') }} ({{ counts.inactive }})
                            </button>
                        </div>

                        <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                            <button v-if="canMultipleDelete && selectedIds.length" type="button" class="btn btn-danger btn-sm btn-wave" @click="confirmDeleteSelected">
                                <i class="ri-delete-bin-line me-1 align-middle"></i>
                                {{ t('support.quick.delete_selected', { count: selectedIds.length }) }}
                            </button>
                            <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ t('support.quick.add_short') }}
                            </button>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th v-if="canMultipleDelete" scope="col" class="ps-4" style="width: 48px;">
                                            <input class="form-check-input" type="checkbox" :checked="allSelected" :disabled="loading || ! rows.length" @change="toggleAll($event.target.checked)">
                                        </th>
                                        <th scope="col">{{ t('support.quick.shortcut') }}</th>
                                        <th scope="col">{{ t('support.quick.name') }}</th>
                                        <th scope="col">{{ t('support.quick.text') }}</th>
                                        <th scope="col">{{ t('support.quick.status') }}</th>
                                        <th scope="col">{{ t('support.quick.created_at') }}</th>
                                        <th v-if="showActions" scope="col" class="text-end pe-4">{{ t('support.quick.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="columnCount" />

                                    <tr v-else-if="! rows.length">
                                        <td :colspan="columnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-flashlight-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('support.quick.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('support.quick.empty') }}</p>
                                                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('support.quick.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr v-for="row in rows" :key="row.id" class="crm-contact">
                                            <td v-if="canMultipleDelete" class="ps-4">
                                                <input class="form-check-input" type="checkbox" :checked="selectedIds.includes(row.id)" @change="toggleRow(row.id, $event.target.checked)">
                                            </td>
                                            <td>
                                                <span class="badge bg-primary-transparent" dir="ltr">/{{ row.shortcut }}</span>
                                            </td>
                                            <td>
                                                <button v-if="canUpdate" type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click="openEdit(row)">
                                                    {{ row.title || '-' }}
                                                </button>
                                                <span v-else class="fw-semibold text-default">{{ row.title || '-' }}</span>
                                                <span class="d-block text-muted fs-11">#{{ row.id }}</span>
                                            </td>
                                            <td class="quick-text text-muted">{{ row.body }}</td>
                                            <td>
                                                <div
                                                    v-if="canChangeStatus"
                                                    class="toggle toggle-success mb-0 catalog-status-toggle"
                                                    :class="{ on: row.status, 'catalog-status-toggle--loading': togglingId === row.id }"
                                                    role="button"
                                                    tabindex="0"
                                                    :aria-busy="togglingId === row.id"
                                                    @click="toggleStatus(row)"
                                                    @keydown.enter.space.prevent="toggleStatus(row)"
                                                >
                                                    <span></span>
                                                </div>
                                                <span v-else class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                    {{ row.status ? t('support.quick.active') : t('support.quick.inactive') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatDate(row.created_at) }}</span>
                                                <span v-if="row.updated_at && row.updated_at !== row.created_at" class="d-block text-muted fs-11">
                                                    {{ t('support.quick.updated') }}: {{ formatDate(row.updated_at) }}
                                                </span>
                                            </td>
                                            <td v-if="showActions" class="text-end pe-4">
                                                <div class="btn-list justify-content-end">
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('support.quick.edit')" @click="openEdit(row)">
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('support.quick.delete')" @click="confirmDelete(row)">
                                                        <i class="ri-delete-bin-line"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="pagination && ! loading" class="card-footer border-top-0">
                        <div class="d-flex align-items-center flex-wrap gap-3">
                            <div class="d-flex align-items-center gap-2 text-muted fs-13">
                                <span>{{ entriesLabel }}</span>
                                <i :class="paginationArrowIcon"></i>
                            </div>

                            <div class="d-flex align-items-center gap-2 ms-md-auto">
                                <label class="text-muted fs-13 mb-0" for="quick-per-page">{{ t('support.quick.per_page') }}</label>
                                <select id="quick-per-page" v-model.number="perPage" class="form-select form-select-sm w-auto">
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="Quick replies pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: ! pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">{{ t('support.quick.previous') }}</button>
                                    </li>
                                    <li v-for="page in pageNumbers" :key="page" class="page-item" :class="{ active: page === currentPage }">
                                        <button type="button" class="page-link" @click="changePage(page)">{{ page }}</button>
                                    </li>
                                    <li class="page-item" :class="{ disabled: ! pagination.next_page_url }">
                                        <button type="button" class="page-link text-primary" @click="changePage(currentPage + 1)">{{ t('support.quick.next') }}</button>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <WalletModal :show="showModal" :title="editingId ? t('support.quick.edit') : t('support.quick.add')" size="md" @close="showModal = false">
            <form id="quick-reply-form" @submit.prevent="save">
                <CatalogTranslationTabs
                    :languages="languages"
                    :active-locale="activeLocale"
                    :translation-tab-class="tabClass"
                    :translation-tab-feedback="tabFeedback"
                    @update:active-locale="activeLocale = $event"
                />

                <template v-if="form.translations[activeLocale]">
                    <div class="mb-3">
                        <label class="form-label" for="qr-title">{{ t('support.quick.name') }} <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="ri-text"></i></span>
                            <input
                                id="qr-title"
                                v-model="form.translations[activeLocale].title"
                                type="text"
                                class="form-control"
                                :class="cls(v$.translations[activeLocale]?.title)"
                                :dir="localeDir"
                                :placeholder="t('support.quick.name_placeholder')"
                            >
                        </div>
                        <div v-if="msg(v$.translations[activeLocale]?.title)" class="invalid-feedback d-block">{{ msg(v$.translations[activeLocale]?.title) }}</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="qr-body">{{ t('support.quick.text') }} <span class="text-danger">*</span></label>
                        <textarea
                            id="qr-body"
                            v-model="form.translations[activeLocale].body"
                            rows="5"
                            class="form-control"
                            :class="cls(v$.translations[activeLocale]?.body)"
                            :dir="localeDir"
                            :placeholder="t('support.quick.text_placeholder')"
                        ></textarea>
                        <div v-if="msg(v$.translations[activeLocale]?.body)" class="invalid-feedback d-block">{{ msg(v$.translations[activeLocale]?.body) }}</div>
                    </div>
                </template>

                <div class="row g-3 align-items-start">
                    <div class="col-md-7">
                        <label class="form-label" for="qr-shortcut">{{ t('support.quick.shortcut') }} <span class="text-danger">*</span></label>
                        <div class="input-group" dir="ltr">
                            <span class="input-group-text bg-light">/</span>
                            <input id="qr-shortcut" v-model="form.shortcut" type="text" class="form-control" :class="cls(v$.shortcut)" placeholder="refund">
                        </div>
                        <div v-if="msg(v$.shortcut) || serverErrors.shortcut" class="invalid-feedback d-block">{{ msg(v$.shortcut) || serverErrors.shortcut }}</div>
                        <div class="text-muted fs-11 mt-1">{{ t('support.quick.hint') }}</div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label d-block mb-2">{{ t('support.quick.status') }}</label>
                        <div
                            class="toggle toggle-success mb-0 catalog-modal-toggle"
                            :class="{ on: form.status }"
                            role="button"
                            tabindex="0"
                            @click="form.status = ! form.status"
                            @keydown.enter.space.prevent="form.status = ! form.status"
                        >
                            <span></span>
                        </div>
                    </div>
                </div>
                <div class="row g-3 mt-0">
                    <div class="col-md-5">
                        <label class="form-label" for="qr-sort">{{ t('support.quick.sort_order') }}</label>
                        <input id="qr-sort" v-model.number="form.sort_order" type="number" min="0" class="form-control">
                    </div>
                </div>
                <div v-if="generalError" class="text-danger mt-2 fs-13">{{ generalError }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('close') }}</button>
                <button type="submit" form="quick-reply-form" class="btn btn-primary btn-wave" :disabled="saving">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ saving ? t('support.quick.saving') : t('save_changes') }}
                </button>
            </template>
        </WalletModal>

        <ConfirmDeleteModal
            :show="deleteConfirm.state.show"
            :title="deleteConfirm.state.title"
            :message="deleteConfirm.state.message"
            :loading="deleteConfirm.state.loading"
            @close="deleteConfirm.close()"
            @confirm="handleDeleteConfirm"
        />
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers } from '@vuelidate/validators';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import ConfirmDeleteModal from '../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../components/wallet/WalletModal.vue';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { useAvailableLanguagesStore } from '../../../../../../stores/availableLanguages';

const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();
const { requiredField, maxString } = useValidation();
const languagesStore = useAvailableLanguagesStore();

const { canCreate, canUpdate, canDelete, canChangeStatus, canMultipleDelete, showActionsColumn: showActions } = useCatalogPermissions('support-quick-replies');

const languages = computed(() => languagesStore.items);
const activeLocale = ref('');
const localeDir = computed(() => ['ar', 'fa', 'ur', 'he'].includes(activeLocale.value) ? 'rtl' : 'ltr');

// ---------------------------------------------------------------- the list (server side: search, status, pages)

const rows = ref([]);
const loading = ref(false);
const pagination = ref(null);
const statusCounts = ref({ total: 0, active: 0, inactive: 0 });
const search = ref('');
const statusFilter = ref('all');
const currentPage = ref(1);
const perPage = ref(15);
const selectedIds = ref([]);
const togglingId = ref(null);

const counts = computed(() => statusCounts.value);
const columnCount = computed(() => 5 + (canMultipleDelete.value ? 1 : 0) + (showActions.value ? 1 : 0));
const allSelected = computed(() => rows.value.length > 0 && rows.value.every((row) => selectedIds.value.includes(row.id)));
const paginationArrowIcon = computed(() => (locale.value === 'ar' ? 'ri-arrow-left-s-line fw-semibold' : 'ri-arrow-right-s-line fw-semibold'));

const entriesLabel = computed(() => (pagination.value
    ? t('support.quick.showing_entries', { from: pagination.value.from ?? 0, to: pagination.value.to ?? 0, total: pagination.value.total ?? 0 })
    : ''));

const pageNumbers = computed(() => {
    if (! pagination.value?.last_page) {
        return [];
    }

    const last = pagination.value.last_page;
    let start = Math.max(1, currentPage.value - 2);
    const end = Math.min(last, start + 4);

    start = Math.max(1, end - 4);

    return Array.from({ length: end - start + 1 }, (_, index) => start + index);
});

function formatDate(value) {
    if (! value) {
        return '-';
    }

    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

async function load(page = currentPage.value) {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/support-quick-replies', {
            params: {
                page,
                per_page: perPage.value,
                search: search.value.trim() || undefined,
                status: statusFilter.value === 'all' ? undefined : statusFilter.value,
                status_counts: 1,
            },
        });

        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? null;
        currentPage.value = pagination.value?.current_page ?? page;
        statusCounts.value = data.status_counts ?? statusCounts.value;
        selectedIds.value = selectedIds.value.filter((id) => rows.value.some((row) => row.id === id));
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

function changePage(page) {
    if (! pagination.value || page < 1 || page > pagination.value.last_page) {
        return;
    }

    load(page);
}

function setStatusFilter(value) {
    statusFilter.value = value;
    load(1);
}

let searchTimer = null;

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(1), 350);
});

watch(perPage, () => load(1));

function toggleRow(id, checked) {
    selectedIds.value = checked ? [...new Set([...selectedIds.value, id])] : selectedIds.value.filter((item) => item !== id);
}

function toggleAll(checked) {
    selectedIds.value = checked ? rows.value.map((row) => row.id) : [];
}

async function toggleStatus(row) {
    if (togglingId.value) {
        return;
    }

    togglingId.value = row.id;

    try {
        await adminAxios.patch(`/api/admin/v1/support-quick-replies/${row.id}/status`, { status: ! row.status });
        row.status = ! row.status;
        load(currentPage.value);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        togglingId.value = null;
    }
}

// ---------------------------------------------------------------- delete (one or the selected)

const deleteConfirm = useConfirmDelete();

function confirmDelete(row) {
    deleteConfirm.open({
        title: t('support.quick.delete'),
        message: t('support.quick.confirm_delete', { name: row.title || `/${row.shortcut}` }),
        payload: { ids: [row.id] },
    });
}

function confirmDeleteSelected() {
    deleteConfirm.open({
        title: t('support.quick.delete'),
        message: t('support.quick.confirm_delete_selected', { count: selectedIds.value.length }),
        payload: { ids: [...selectedIds.value] },
    });
}

async function handleDeleteConfirm() {
    const ids = deleteConfirm.state.payload?.ids ?? [];

    deleteConfirm.setLoading(true);

    try {
        if (ids.length === 1) {
            await adminAxios.delete(`/api/admin/v1/support-quick-replies/${ids[0]}`);
        } else {
            await adminAxios.post('/api/admin/v1/support-quick-replies/delete-multiple', { ids });
        }

        showSuccess(t('support.quick.deleted'));
        selectedIds.value = [];
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
        await load(rows.value.length === ids.length && currentPage.value > 1 ? currentPage.value - 1 : currentPage.value);
    } catch (error) {
        deleteConfirm.setLoading(false);
        showError(extractApiErrorMessage(error));
    }
}

// ---------------------------------------------------------------- the form

const showModal = ref(false);
const editingId = ref(null);
const saving = ref(false);
const generalError = ref('');
const serverErrors = reactive({});
const form = reactive({ shortcut: '', sort_order: 0, status: true, translations: {} });

const rules = computed(() => ({
    shortcut: {
        required: requiredField('support.quick.shortcut'),
        max: maxString('support.quick.shortcut', 40),
        format: helpers.withMessage(
            () => t('validation.regex', { field: t('support.quick.shortcut') }),
            (value) => ! value || /^[\p{L}\p{N}_-]+$/u.test(String(value).replace(/^\//, '')),
        ),
    },
    translations: Object.fromEntries(languages.value.map((lang) => [lang.code, {
        title: { required: requiredField('support.quick.name'), max: maxString('support.quick.name', 120) },
        body: { required: requiredField('support.quick.text'), max: maxString('support.quick.text', 4000) },
    }])),
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const msg = (field) => field?.$errors?.[0]?.$message || '';
const cls = (field) => ({ 'is-invalid': Boolean(field?.$error) });

/** A tab shows a tick once its language has a name and a text, a warning when it is incomplete after a try. */
function tabState(code) {
    const entry = form.translations[code] ?? {};

    return {
        filled: Boolean((entry.title || '').trim() && (entry.body || '').trim()),
        failed: Boolean(v$.value.translations[code]?.$error),
    };
}

function tabClass(code) {
    const { filled, failed } = tabState(code);

    return { active: activeLocale.value === code, 'catalog-lang-tab--error': failed, 'catalog-lang-tab--valid': filled };
}

function tabFeedback(code) {
    const { filled, failed } = tabState(code);

    return { show: filled || failed, valid: filled && ! failed };
}

function ensureTranslations() {
    languages.value.forEach((lang) => { form.translations[lang.code] ??= { title: '', body: '' }; });

    if (! activeLocale.value && languages.value.length) {
        activeLocale.value = languages.value[0].code;
    }
}

function resetErrors() {
    Object.keys(serverErrors).forEach((key) => delete serverErrors[key]);
    generalError.value = '';
    v$.value.$reset();
}

function openCreate() {
    editingId.value = null;
    Object.assign(form, { shortcut: '', sort_order: pagination.value?.total ?? 0, status: true, translations: {} });
    ensureTranslations();
    activeLocale.value = languages.value[0]?.code || '';
    resetErrors();
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    Object.assign(form, { shortcut: row.shortcut, sort_order: row.sort_order ?? 0, status: Boolean(row.status), translations: {} });
    ensureTranslations();
    (row.translations ?? []).forEach((tr) => { form.translations[tr.locale] = { title: tr.title ?? '', body: tr.body ?? '' }; });
    activeLocale.value = languages.value[0]?.code || '';
    resetErrors();
    showModal.value = true;
}

async function save() {
    Object.keys(serverErrors).forEach((key) => delete serverErrors[key]);
    generalError.value = '';

    if (! await v$.value.$validate()) {
        // Show the language that is still incomplete.
        const bad = languages.value.find((lang) => v$.value.translations[lang.code]?.$error);

        if (bad) {
            activeLocale.value = bad.code;
        }

        return;
    }

    saving.value = true;

    const payload = {
        shortcut: form.shortcut,
        sort_order: form.sort_order || 0,
        status: form.status,
        translations: languages.value.map((lang) => ({ locale: lang.code, title: form.translations[lang.code].title, body: form.translations[lang.code].body })),
    };

    try {
        if (editingId.value) {
            await adminAxios.put(`/api/admin/v1/support-quick-replies/${editingId.value}`, payload);
        } else {
            await adminAxios.post('/api/admin/v1/support-quick-replies', payload);
        }

        showSuccess(t('support.quick.saved'));
        showModal.value = false;
        load(currentPage.value);
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};

        Object.entries(bag).forEach(([key, messages]) => { serverErrors[key.split('.')[0]] = messages[0]; });
        generalError.value = Object.keys(bag).length ? '' : extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}

watch(languages, () => ensureTranslations(), { immediate: true });

onMounted(async () => {
    load(1);
    await languagesStore.fetch();
});
</script>

<style scoped>
.quick-text {
    max-width: 380px;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>
