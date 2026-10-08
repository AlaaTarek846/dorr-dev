<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('support.help.title') }}
                <span class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ counts.total }}</span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('support.help.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border d-flex gap-2 align-items-start fs-13">
            <i class="ri-information-line text-primary mt-1"></i>
            <span>{{ t('support.help.intro') }}</span>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card custom-card mb-0"><div class="card-body d-flex align-items-center gap-3">
                    <span class="avatar avatar-md avatar-rounded bg-success-transparent"><i class="ri-checkbox-circle-line fs-5 text-success"></i></span>
                    <div><div class="text-muted fs-12">{{ t('support.help.stat_solved') }}</div><div class="fw-semibold fs-18">{{ summary.solved }}</div></div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card custom-card mb-0"><div class="card-body d-flex align-items-center gap-3">
                    <span class="avatar avatar-md avatar-rounded bg-warning-transparent"><i class="ri-customer-service-2-line fs-5 text-warning"></i></span>
                    <div><div class="text-muted fs-12">{{ t('support.help.stat_agent') }}</div><div class="fw-semibold fs-18">{{ summary.agent }}</div></div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card custom-card mb-0"><div class="card-body d-flex align-items-center gap-3">
                    <span class="avatar avatar-md avatar-rounded bg-primary-transparent"><i class="ri-percent-line fs-5 text-primary"></i></span>
                    <div><div class="text-muted fs-12">{{ t('support.help.stat_rate') }}</div><div class="fw-semibold fs-18" dir="ltr">{{ summary.solved_rate === null ? '-' : summary.solved_rate + '%' }}</div></div>
                </div></div>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                        <div class="d-flex flex-wrap align-items-center gap-1 catalog-toolbar-filters">
                            <div class="input-group input-group-sm catalog-toolbar-search">
                                <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                                <input v-model="search" type="search" class="form-control" :placeholder="t('support.help.search')">
                                <button v-if="search" type="button" class="btn btn-light border catalog-search-clear" :title="t('support.quick.clear_search')" @click="search = ''">
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>
                            <button v-if="statusFilter !== 'all'" type="button" class="btn btn-sm catalog-filter-btn catalog-filter-btn--all-idle" @click="setStatusFilter('all')">
                                {{ t('support.quick.filter_all') }} ({{ counts.total }})
                            </button>
                            <button type="button" class="btn btn-sm catalog-filter-btn" :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'" @click="setStatusFilter('active')">
                                {{ t('support.quick.filter_active') }} ({{ counts.active }})
                            </button>
                            <button type="button" class="btn btn-sm catalog-filter-btn" :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'" @click="setStatusFilter('inactive')">
                                {{ t('support.quick.filter_inactive') }} ({{ counts.inactive }})
                            </button>
                        </div>

                        <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                            <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ path.length ? t('support.help.add_sub') : t('support.help.add_short') }}
                            </button>
                        </div>
                    </div>

                    <!-- where we are in the tree -->
                    <div class="help-path px-4 py-2 border-top border-bottom bg-light d-flex align-items-center flex-wrap gap-1 fs-13">
                        <i class="ri-node-tree text-muted me-1"></i>
                        <button type="button" class="btn btn-link p-0 fs-13" :class="{ 'fw-semibold text-default': ! path.length }" @click="goTo(null)">{{ t('support.help.main_menu') }}</button>
                        <template v-for="(crumb, index) in path" :key="crumb.id">
                            <i :class="arrowIcon" class="text-muted"></i>
                            <button type="button" class="btn btn-link p-0 fs-13" :class="{ 'fw-semibold text-default': index === path.length - 1 }" @click="goTo(crumb.id)">{{ crumb.title || `#${crumb.id}` }}</button>
                        </template>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col" class="ps-4">{{ t('support.help.topic') }}</th>
                                        <th scope="col">{{ t('support.help.answer') }}</th>
                                        <th scope="col">{{ t('support.help.sub_topics') }}</th>
                                        <th scope="col">{{ t('support.help.col_solved') }}</th>
                                        <th scope="col">{{ t('support.help.col_agent') }}</th>
                                        <th scope="col">{{ t('support.quick.sort_order') }}</th>
                                        <th scope="col">{{ t('support.quick.status') }}</th>
                                        <th scope="col">{{ t('support.quick.created_at') }}</th>
                                        <th v-if="showActions" scope="col" class="text-end pe-4">{{ t('support.quick.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="6" :columns="columnCount" />

                                    <tr v-else-if="! rows.length">
                                        <td :colspan="columnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-node-tree fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('support.help.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('support.help.empty') }}</p>
                                                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ path.length ? t('support.help.add_sub') : t('support.help.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr v-for="row in rows" :key="row.id" class="crm-contact">
                                            <td class="ps-4">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="avatar avatar-sm avatar-rounded bg-primary-transparent">
                                                        <i :class="row.children_count ? 'ri-folder-3-line' : 'ri-question-answer-line'" class="text-primary"></i>
                                                    </span>
                                                    <div>
                                                        <button type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click="goTo(row.id)">{{ row.title || '-' }}</button>
                                                        <span class="d-block text-muted fs-11">#{{ row.id }}</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="help-text text-muted">
                                                <span v-if="row.answer">{{ row.answer }}</span>
                                                <span v-else-if="! row.children_count" class="badge bg-warning-transparent">{{ t('support.help.needs_answer') }}</span>
                                                <span v-else>-</span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-light" :title="t('support.help.open')" @click="goTo(row.id)">
                                                    <i class="ri-folder-open-line me-1"></i>{{ row.children_count }}
                                                </button>
                                            </td>
                                            <td><span class="badge bg-success-transparent">{{ row.solved_count }}</span></td>
                                            <td><span class="badge bg-warning-transparent">{{ row.agent_count }}</span></td>
                                            <td>{{ row.sort_order }}</td>
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
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('support.help.edit')" @click="openEdit(row)">
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
                </div>
            </div>
        </div>

        <WalletModal :show="showModal" :title="editingId ? t('support.help.edit') : (path.length ? t('support.help.add_sub') : t('support.help.add'))" size="md" @close="showModal = false">
            <form id="help-node-form" @submit.prevent="save">
                <CatalogTranslationTabs
                    :languages="languages"
                    :active-locale="activeLocale"
                    :translation-tab-class="tabClass"
                    :translation-tab-feedback="tabFeedback"
                    @update:active-locale="activeLocale = $event"
                />

                <template v-if="form.translations[activeLocale]">
                    <div class="mb-3">
                        <label class="form-label" for="hn-title">{{ t('support.help.topic') }} <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="ri-text"></i></span>
                            <input
                                id="hn-title"
                                v-model="form.translations[activeLocale].title"
                                type="text"
                                class="form-control"
                                :class="cls(v$.translations[activeLocale]?.title)"
                                :dir="localeDir"
                                :placeholder="t('support.help.topic_placeholder')"
                            >
                        </div>
                        <div v-if="msg(v$.translations[activeLocale]?.title)" class="invalid-feedback d-block">{{ msg(v$.translations[activeLocale]?.title) }}</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="hn-answer">{{ t('support.help.answer') }}</label>
                        <textarea
                            id="hn-answer"
                            v-model="form.translations[activeLocale].answer"
                            rows="5"
                            class="form-control"
                            :class="cls(v$.translations[activeLocale]?.answer)"
                            :dir="localeDir"
                            :placeholder="t('support.help.answer_placeholder')"
                        ></textarea>
                        <div v-if="msg(v$.translations[activeLocale]?.answer)" class="invalid-feedback d-block">{{ msg(v$.translations[activeLocale]?.answer) }}</div>
                        <div class="text-muted fs-11 mt-1">{{ t('support.help.answer_hint') }}</div>
                    </div>
                </template>

                <div class="mb-3">
                    <label class="form-label" for="hn-parent">{{ t('support.help.parent') }}</label>
                    <TreeSelect
                        id="hn-parent"
                        v-model="parentSelection"
                        v-model:expanded-keys="expandedKeys"
                        :options="parentTree"
                        selection-mode="single"
                        :meta-key-selection="false"
                        filter
                        :filter-placeholder="t('support.help.search')"
                        :placeholder="t('support.help.main_menu')"
                        append-to="self"
                        class="w-100"
                    />
                    <div v-if="editingId" class="text-muted fs-11 mt-1">{{ t('support.help.parent_hint') }}</div>
                </div>

                <div class="row g-3 align-items-end">
                    <div class="col-8">
                        <label class="form-label" for="hn-sort">{{ t('support.quick.sort_order') }}</label>
                        <input id="hn-sort" v-model.number="form.sort_order" type="number" min="0" class="form-control">
                    </div>
                    <div class="col-4">
                        <label class="form-label d-block mb-2">{{ t('support.quick.status') }}</label>
                        <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="button" tabindex="0" @click="form.status = ! form.status" @keydown.enter.space.prevent="form.status = ! form.status">
                            <span></span>
                        </div>
                    </div>
                </div>
                <div v-if="generalError" class="text-danger mt-2 fs-13">{{ generalError }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('close') }}</button>
                <button type="submit" form="help-node-form" class="btn btn-primary btn-wave" :disabled="saving">
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
import TreeSelect from 'primevue/treeselect';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import ConfirmDeleteModal from '../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../components/wallet/WalletModal.vue';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { useAvailableLanguagesStore } from '../../../../../../stores/availableLanguages';

const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();
const { requiredField, maxString } = useValidation();
const languagesStore = useAvailableLanguagesStore();

const { canCreate, canUpdate, canDelete, canChangeStatus, showActionsColumn: showActions } = useCatalogPermissions('support-help-nodes');

const languages = computed(() => languagesStore.items);
const activeLocale = ref('');
const localeDir = computed(() => ['ar', 'fa', 'ur', 'he'].includes(activeLocale.value) ? 'rtl' : 'ltr');
const arrowIcon = computed(() => (locale.value === 'ar' ? 'ri-arrow-left-s-line' : 'ri-arrow-right-s-line'));

// ---------------------------------------------------------------- one level of the tree at a time

const rows = ref([]);
const path = ref([]);
const parentId = ref(null);
const loading = ref(false);
const search = ref('');
const statusFilter = ref('all');
const statusCounts = ref({ total: 0, active: 0, inactive: 0 });
const summary = ref({ solved: 0, agent: 0, solved_rate: null });
const togglingId = ref(null);

const counts = computed(() => statusCounts.value);
const columnCount = computed(() => 8 + (showActions.value ? 1 : 0));

function formatDate(value) {
    if (! value) {
        return '-';
    }

    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

async function load() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/support-help-nodes', {
            params: {
                parent_id: parentId.value ?? undefined,
                search: search.value.trim() || undefined,
                status: statusFilter.value === 'all' ? undefined : statusFilter.value,
            },
        });

        rows.value = data.data ?? [];
        path.value = data.path ?? [];
        statusCounts.value = data.status_counts ?? statusCounts.value;
        summary.value = data.summary ?? summary.value;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

function goTo(id) {
    parentId.value = id;
    search.value = '';
    statusFilter.value = 'all';
    load();
}

function setStatusFilter(value) {
    statusFilter.value = value;
    load();
}

let searchTimer = null;

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 350);
});

async function toggleStatus(row) {
    if (togglingId.value) {
        return;
    }

    togglingId.value = row.id;

    try {
        await adminAxios.patch(`/api/admin/v1/support-help-nodes/${row.id}/status`, { status: ! row.status });
        row.status = ! row.status;
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        togglingId.value = null;
    }
}

// ---------------------------------------------------------------- delete

const deleteConfirm = useConfirmDelete();

function confirmDelete(row) {
    deleteConfirm.open({
        title: t('support.quick.delete'),
        message: t(row.children_count ? 'support.help.confirm_delete_tree' : 'support.help.confirm_delete', { name: row.title || `#${row.id}`, count: row.children_count }),
        payload: { id: row.id },
    });
}

async function handleDeleteConfirm() {
    deleteConfirm.setLoading(true);

    try {
        await adminAxios.delete(`/api/admin/v1/support-help-nodes/${deleteConfirm.state.payload.id}`);
        showSuccess(t('support.quick.deleted'));
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
        load();
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
const form = reactive({ parent_id: null, sort_order: 0, status: true, translations: {} });
const parentOptions = ref([]);
const expandedKeys = ref({});

// The main menu is the root of the tree select; a topic picked there sits at the top level.
const parentTree = computed(() => [{ key: 'root', label: t('support.help.main_menu'), selectable: true, children: parentOptions.value }]);

const parentSelection = computed({
    get: () => ({ [form.parent_id ?? 'root']: true }),
    set: (value) => {
        const key = Object.keys(value ?? {})[0];

        form.parent_id = ! key || key === 'root' ? null : Number(key);
    },
});

function expandAll(nodes, into = { root: true }) {
    nodes.forEach((node) => {
        into[node.key] = true;
        expandAll(node.children ?? [], into);
    });

    return into;
}

/** Where the topic can sit: not under itself or its own branch, and a place too deep for it cannot be picked. */
async function loadParentOptions(excludeId = null) {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/support-help-nodes/options', { params: { exclude: excludeId ?? undefined } });

        parentOptions.value = data.data ?? [];
    } catch {
        parentOptions.value = [];
    }

    expandedKeys.value = expandAll(parentOptions.value);
}

const rules = computed(() => ({
    translations: Object.fromEntries(languages.value.map((lang) => [lang.code, {
        title: { required: requiredField('support.help.topic'), max: maxString('support.help.topic', 150) },
        answer: { max: maxString('support.help.answer', 4000) },
    }])),
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const msg = (field) => field?.$errors?.[0]?.$message || '';
const cls = (field) => ({ 'is-invalid': Boolean(field?.$error) });

/** A tab shows a tick once its language has a title, a warning when it is missing after a try. */
function tabState(code) {
    return {
        filled: Boolean((form.translations[code]?.title || '').trim()),
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
    languages.value.forEach((lang) => { form.translations[lang.code] ??= { title: '', answer: '' }; });

    if (! activeLocale.value && languages.value.length) {
        activeLocale.value = languages.value[0].code;
    }
}

function openCreate() {
    editingId.value = null;
    Object.assign(form, { parent_id: parentId.value, sort_order: rows.value.length + 1, status: true, translations: {} });
    loadParentOptions();
    ensureTranslations();
    activeLocale.value = languages.value[0]?.code || '';
    generalError.value = '';
    v$.value.$reset();
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    Object.assign(form, { parent_id: row.parent_id ?? null, sort_order: row.sort_order ?? 0, status: Boolean(row.status), translations: {} });
    loadParentOptions(row.id);
    ensureTranslations();
    (row.translations ?? []).forEach((tr) => { form.translations[tr.locale] = { title: tr.title ?? '', answer: tr.answer ?? '' }; });
    activeLocale.value = languages.value[0]?.code || '';
    generalError.value = '';
    v$.value.$reset();
    showModal.value = true;
}

async function save() {
    generalError.value = '';

    if (! await v$.value.$validate()) {
        // Show the language whose title is missing.
        const bad = languages.value.find((lang) => v$.value.translations[lang.code]?.$error);

        if (bad) {
            activeLocale.value = bad.code;
        }

        return;
    }

    saving.value = true;

    const payload = {
        parent_id: form.parent_id,
        sort_order: form.sort_order || 0,
        status: form.status,
        translations: languages.value.map((lang) => ({ locale: lang.code, title: form.translations[lang.code].title, answer: form.translations[lang.code].answer || null })),
    };

    try {
        if (editingId.value) {
            await adminAxios.put(`/api/admin/v1/support-help-nodes/${editingId.value}`, payload);
        } else {
            await adminAxios.post('/api/admin/v1/support-help-nodes', payload);
        }

        showSuccess(t('support.quick.saved'));
        showModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};

        generalError.value = Object.values(bag)[0]?.[0] ?? extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}

watch(languages, () => ensureTranslations(), { immediate: true });

onMounted(async () => {
    load();
    await languagesStore.fetch();
});
</script>

<style scoped>
.help-text {
    max-width: 380px;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>
