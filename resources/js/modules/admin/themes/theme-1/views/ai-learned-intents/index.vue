<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <h1 class="page-title fw-semibold fs-18 mb-0">
                    {{ t('ai_learned_intents.title') }}
                    <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ pagination.total }}</span>
                </h1>
                <span class="fs-semibold text-muted">{{ t('ai_learned_intents.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_learned_intents.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <!-- Stats -->
        <div class="row">
            <div v-for="card in statCards" :key="card.key" class="col-xxl col-xl-4 col-md-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3">
                            <span class="avatar avatar-md avatar-rounded" :class="card.tone">
                                <i :class="card.icon" class="fs-18"></i>
                            </span>
                            <div class="flex-fill">
                                <span class="d-block text-muted fs-12">{{ card.label }}</span>
                                <h4 class="fw-semibold mb-0">
                                    <span v-if="statsLoading" class="placeholder col-4"></span>
                                    <template v-else>{{ card.value }}</template>
                                </h4>
                            </div>
                        </div>
                        <p v-if="card.hint" class="text-muted fs-11 mb-0 mt-2">{{ card.hint }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Phrase tester (POST /test = ai-learned-intents.update) -->
        <div v-if="canUpdate" class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header">
                        <div class="card-title">{{ t('ai_learned_intents.tester_title') }}</div>
                    </div>
                    <div class="card-body">
                        <p class="text-muted fs-13 mb-3">{{ t('ai_learned_intents.tester_hint') }}</p>
                        <form class="row g-2 align-items-center" @submit.prevent="runTest">
                            <div class="col-lg">
                                <input v-model="testMessage" type="text" dir="auto" maxlength="600" class="form-control" :placeholder="t('ai_learned_intents.tester_placeholder')">
                            </div>
                            <div class="col-lg-auto">
                                <div class="form-check mb-0">
                                    <input id="tester-recent-image" v-model="testRecentImage" type="checkbox" class="form-check-input">
                                    <label class="form-check-label fs-13" for="tester-recent-image">{{ t('ai_learned_intents.tester_recent_image') }}</label>
                                </div>
                            </div>
                            <div class="col-lg-auto">
                                <button type="submit" class="btn btn-primary btn-wave" :disabled="testing || ! testMessage.trim()">
                                    <span v-if="testing" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="ri-flask-line me-1 align-middle"></i>
                                    {{ t('ai_learned_intents.tester_run') }}
                                </button>
                            </div>
                        </form>

                        <div v-if="testResult" class="mt-3 p-3 rounded border bg-light">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge" :class="stageTone(testResult.stage)">{{ t(`ai_learned_intents.stage.${testResult.stage}`) }}</span>
                                <span v-for="intent in testResult.intents" :key="intent" class="badge bg-primary-transparent">
                                    <i :class="intentIcon(intent)" class="me-1"></i>{{ intentLabel(intent) }}
                                </span>
                                <span v-if="testResult.file_format" class="badge bg-info-transparent text-uppercase">{{ testResult.file_format }}</span>
                            </div>
                            <p class="text-muted fs-12 mb-1">{{ t(`ai_learned_intents.stage_hint.${testResult.stage}`) }}</p>
                            <p class="text-muted fs-11 mb-0">
                                {{ t('ai_learned_intents.normalized_as') }}: <code dir="auto">{{ testResult.normalized }}</code>
                                <span v-if="testResult.learned_id"> · #{{ testResult.learned_id }}</span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- The list (like countries) -->
        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                        <div class="d-flex flex-wrap align-items-center gap-1 catalog-toolbar-filters">
                            <div class="input-group input-group-sm catalog-toolbar-search">
                                <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                                <input v-model="search" type="search" dir="auto" class="form-control" :placeholder="t('ai_learned_intents.search_placeholder')">
                                <button v-if="search" type="button" class="btn btn-light border catalog-search-clear" :title="t('ai_learned_intents.clear_search')" @click="search = ''">
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button v-if="statusFilter !== 'all'" type="button" class="btn btn-sm catalog-filter-btn catalog-filter-btn--all-idle" @click="setStatusFilter('all')">
                                {{ t('ai_learned_intents.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('ai_learned_intents.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('ai_learned_intents.filter_inactive') }} ({{ counts.inactive }})
                            </button>
                            <button
                                v-if="counts.deleted > 0"
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'deleted' ? 'catalog-filter-btn--deleted' : 'catalog-filter-btn--deleted-idle'"
                                @click="setStatusFilter('deleted')"
                            >
                                {{ t('catalog.filter_deleted') }} ({{ counts.deleted }})
                            </button>
                        </div>

                        <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                            <button v-if="canMultipleDelete && selectedCount && statusFilter !== 'deleted'" type="button" class="btn btn-danger btn-sm btn-wave" @click="confirmDeleteSelected">
                                <i class="ri-delete-bin-line me-1 align-middle"></i>{{ t('catalog.bulk_delete_count', { count: selectedCount }) }}
                            </button>
                            <button v-if="canDelete && selectedCount && statusFilter === 'deleted'" type="button" class="btn btn-danger btn-sm btn-wave" @click="confirmForceDeleteSelected">
                                <i class="ri-delete-bin-7-line me-1 align-middle"></i>{{ t('catalog.force_delete_count', { count: selectedCount }) }}
                            </button>
                            <button v-if="canCreate && statusFilter !== 'deleted'" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                <i class="ri-add-line me-1 align-middle"></i>{{ t('ai_learned_intents.add_short') }}
                            </button>
                        </div>

                        <!-- The screen's own filters (sent with the list and its counts) -->
                        <div class="d-flex flex-wrap gap-2 w-100">
                            <select v-model="filters.state" class="form-select form-select-sm w-auto" @change="applyFilters">
                                <option value="">{{ t('ai_learned_intents.filter_all_statuses') }}</option>
                                <option value="pending">{{ t('ai_learned_intents.status_pending') }}</option>
                                <option value="conflict">{{ t('ai_learned_intents.status_conflict') }}</option>
                            </select>
                            <select v-model="filters.intent" class="form-select form-select-sm w-auto" @change="applyFilters">
                                <option value="">{{ t('ai_learned_intents.filter_all_intents') }}</option>
                                <option v-for="intent in INTENTS" :key="intent" :value="intent">{{ intentLabel(intent) }}</option>
                            </select>
                            <select v-model="filters.mode" class="form-select form-select-sm w-auto" @change="applyFilters">
                                <option value="">{{ t('ai_learned_intents.filter_all_modes') }}</option>
                                <option value="phrase">{{ t('ai_learned_intents.mode_phrase') }}</option>
                                <option value="exact">{{ t('ai_learned_intents.mode_exact') }}</option>
                            </select>
                            <select v-model="filters.source" class="form-select form-select-sm w-auto" @change="applyFilters">
                                <option value="">{{ t('ai_learned_intents.filter_all_sources') }}</option>
                                <option value="model">{{ t('ai_learned_intents.source_model') }}</option>
                                <option value="admin">{{ t('ai_learned_intents.source_admin') }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th v-if="canMultipleDelete" scope="col" class="ps-4" style="width: 48px;">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="allSelected"
                                                :disabled="loading || !items.length"
                                                :aria-label="t('ai_learned_intents.select_all')"
                                                @change="toggleSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('ai_learned_intents.phrase') }}</th>
                                        <th scope="col">{{ t('ai_learned_intents.intent') }}</th>
                                        <th scope="col">{{ t('ai_learned_intents.state') }}</th>
                                        <th scope="col">{{ t('ai_learned_intents.confidence') }}</th>
                                        <th scope="col">{{ t('ai_learned_intents.hits') }}</th>
                                        <th scope="col">{{ t('ai_learned_intents.source') }}</th>
                                        <th scope="col">{{ t('ai_learned_intents.status') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_learned_intents.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="tableColumnCount" />

                                    <tr v-else-if="!items.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3"><i class="ri-book-2-line fs-2 text-primary"></i></span>
                                                <p class="fw-semibold mb-1">{{ t('ai_learned_intents.empty_title') }}</p>
                                                <p class="text-muted mb-0">{{ t('ai_learned_intents.empty') }}</p>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr v-for="record in items" :key="record.id">
                                            <td v-if="canMultipleDelete" class="ps-4">
                                                <input class="form-check-input" type="checkbox" :checked="isSelected(record.id)" :aria-label="record.phrase" @change="toggleSelect(record.id, $event.target.checked)">
                                            </td>
                                            <td>
                                                <button v-if="canUpdate && !isTrashedRecord(record)" type="button" class="btn btn-link p-0 text-start fw-semibold text-default text-wrap" dir="auto" style="max-width: 320px" @click="openEdit(record)">
                                                    {{ record.phrase }}
                                                </button>
                                                <span v-else class="d-block fw-semibold text-wrap" dir="auto" style="max-width: 320px">{{ record.phrase }}</span>
                                                <span class="badge bg-light text-muted fs-10 mt-1">{{ t(`ai_learned_intents.mode_${record.match_mode}`) }}</span>
                                                <span class="badge bg-light text-muted fs-10 mt-1 text-uppercase">{{ record.language }}</span>
                                                <span class="d-block text-muted fs-11">#{{ record.id }}</span>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary-transparent"><i :class="intentIcon(record.intent)" class="me-1"></i>{{ intentLabel(record.intent) }}</span>
                                                <span v-if="record.file_format" class="badge bg-info-transparent text-uppercase ms-1">{{ record.file_format }}</span>
                                            </td>
                                            <td>
                                                <span class="badge" :class="stateTone(record.state)">{{ t(`ai_learned_intents.status_${record.state}`) }}</span>
                                                <span v-if="record.state === 'pending'" class="d-block text-muted fs-11 mt-1">
                                                    {{ t('ai_learned_intents.confirmations_progress', { n: record.confirmations, total: minConfirmations }) }}
                                                </span>
                                                <span v-if="record.state === 'conflict'" class="d-block text-danger fs-11 mt-1">{{ t('ai_learned_intents.conflicts_count', { n: record.conflicts }) }}</span>
                                            </td>
                                            <td style="min-width: 110px">
                                                <div class="progress progress-xs" role="progressbar">
                                                    <div class="progress-bar" :class="confidenceTone(record.confidence)" :style="{ width: `${Math.round(record.confidence * 100)}%` }"></div>
                                                </div>
                                                <span class="text-muted fs-11">{{ Math.round(record.confidence * 100) }}%</span>
                                            </td>
                                            <td>
                                                <span class="fw-semibold">{{ record.hits }}</span>
                                                <span v-if="record.last_hit_at" class="d-block text-muted fs-11">{{ formatCatalogDate(record.last_hit_at, locale) }}</span>
                                            </td>
                                            <td>
                                                <span class="badge" :class="record.source === 'admin' ? 'bg-secondary-transparent' : 'bg-light text-default'">
                                                    <i :class="record.source === 'admin' ? 'ri-user-settings-line' : 'ri-robot-2-line'" class="me-1"></i>{{ t(`ai_learned_intents.source_${record.source}`) }}
                                                </span>
                                                <span v-if="record.learned_with_model" class="d-block text-muted fs-11">{{ record.learned_with_model }}</span>
                                            </td>
                                            <td>
                                                <span v-if="isTrashedRecord(record)" class="badge bg-danger-transparent">{{ t('catalog.deleted_badge') }}</span>
                                                <div
                                                    v-else-if="canChangeStatus"
                                                    class="toggle toggle-success mb-0 catalog-status-toggle"
                                                    :class="{ on: record.status, 'catalog-status-toggle--loading': isTogglingStatus(record.id) }"
                                                    role="switch"
                                                    tabindex="0"
                                                    :aria-checked="record.status"
                                                    :aria-busy="isTogglingStatus(record.id)"
                                                    :title="record.status ? t('ai_learned_intents.disable') : t('ai_learned_intents.enable')"
                                                    @click="toggleRow(record)"
                                                    @keydown.enter.space.prevent="toggleRow(record)"
                                                >
                                                    <span></span>
                                                </div>
                                                <span v-else class="badge" :class="record.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                    {{ record.status ? t('ai_learned_intents.filter_active') : t('ai_learned_intents.filter_inactive') }}
                                                </span>
                                                <span v-if="catalogShowDeletedSubtext(record, isDeletedView)" class="d-block text-muted fs-11">{{ t('catalog.deleted_at') }}: {{ formatCatalogDate(record.deleted_at, locale) }}</span>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div v-if="isTrashedRecord(record)" class="btn-list justify-content-end">
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-success-light btn-icon" :title="t('catalog.restore_title')" @click="confirmRestore(record.id)">
                                                        <i class="ri-arrow-go-back-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('catalog.force_delete_title')" @click="confirmForceDelete(record.id)">
                                                        <i class="ri-delete-bin-7-line"></i>
                                                    </button>
                                                </div>
                                                <div v-else class="btn-list justify-content-end">
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('ai_learned_intents.edit_title')" @click="openEdit(record)">
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('ai_learned_intents.delete_title')" @click="confirmDelete(record.id)">
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

                    <AdminPaginationFooter
                        :pagination="pagination"
                        :current-page="currentPage"
                        :per-page="perPage"
                        :per-page-options="[15, 25, 50]"
                        :loading="loading"
                        @change-page="changePage"
                        @change-per-page="perPage = $event"
                    />
                </div>
            </div>
        </div>

        <ModalCreateAndUpdate :show="modalShow" :type="modalType" :record="selectedRecord" :intents="INTENTS" @close="modalShow = false" @saved="onSaved" />

        <ConfirmDeleteModal
            :show="deleteConfirm.state.show"
            :title="deleteConfirm.state.title"
            :message="deleteConfirm.state.message"
            :loading="deleteConfirm.state.loading"
            @close="deleteConfirm.close()"
            @confirm="onDeleteConfirm"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import ConfirmDeleteModal from '../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import { useAiLearnedIntents } from '../../../../../../composables/useAiRoutingScreens';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useCatalogTrashActions } from '../../../../../../composables/useCatalogTrashActions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';
import { useAiLearnedIntentsStore } from '../../../../../../stores/aiRoutingScreens';
import { catalogShowDeletedSubtext, formatCatalogDate, isTrashedRecord } from '../../../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const { showError } = useToast();

const BASE = '/api/admin/v1/ai-learned-intents';

// Must mirror Modules\AI\Support\AiChatIntent::ACTIONS.
const INTENTS = [
    'image_generation', 'image_edit', 'voice_reply', 'file_output', 'web_search',
    'research', 'study', 'coding', 'structured_output',
];

const INTENT_ICONS = {
    image_generation: 'ri-image-add-line',
    image_edit: 'ri-image-edit-line',
    voice_reply: 'ri-mic-line',
    file_output: 'ri-file-download-line',
    web_search: 'ri-global-line',
    research: 'ri-microscope-line',
    study: 'ri-graduation-cap-line',
    coding: 'ri-code-s-slash-line',
    structured_output: 'ri-braces-line',
};

const countsStore = useAiLearnedIntentsStore();
const { canCreate, canUpdate, canDelete, canChangeStatus, canMultipleDelete } = useCatalogPermissions('ai-learned-intents');

// The screen's own filters, sent with the list and its counts (AiLearnedIntentRepository).
const filters = reactive({ state: '', intent: '', mode: '', source: '' });

const catalog = useAiLearnedIntents({
    getFilters: () => Object.fromEntries(Object.entries(filters).filter(([, value]) => value)),
});
const { items, loading, pagination, selectedIds, currentPage, perPage, search, statusFilter, isDeletedView } = catalog;
const { fetchItems, setStatusFilter, toggleStatus, toggleSelectAll, toggleSelect, isTogglingStatus } = catalog;

const tableColumnCount = computed(() => 8 + (canMultipleDelete.value ? 1 : 0));

const counts = computed(() => ({
    total: countsStore.total ?? pagination.value?.total ?? 0,
    active: countsStore.activeCount ?? 0,
    inactive: countsStore.inactiveCount ?? 0,
    deleted: countsStore.deletedCount ?? 0,
}));

const stats = ref(null);
const statsLoading = ref(true);
const testMessage = ref('');
const testRecentImage = ref(false);
const testing = ref(false);
const testResult = ref(null);

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const deleteConfirm = useConfirmDelete();

const minConfirmations = computed(() => stats.value?.min_confirmations ?? 2);

const statCards = computed(() => [
    { key: 'total', label: t('ai_learned_intents.stat_total'), value: stats.value?.total ?? 0, icon: 'ri-book-2-line', tone: 'bg-primary-transparent text-primary' },
    { key: 'active', label: t('ai_learned_intents.stat_active'), value: stats.value?.active ?? 0, icon: 'ri-checkbox-circle-line', tone: 'bg-success-transparent text-success' },
    { key: 'pending', label: t('ai_learned_intents.stat_pending'), value: stats.value?.pending ?? 0, icon: 'ri-time-line', tone: 'bg-warning-transparent text-warning', hint: t('ai_learned_intents.stat_pending_hint', { n: minConfirmations.value }) },
    { key: 'conflicts', label: t('ai_learned_intents.stat_conflicts'), value: stats.value?.conflicts ?? 0, icon: 'ri-error-warning-line', tone: 'bg-danger-transparent text-danger', hint: t('ai_learned_intents.stat_conflicts_hint') },
    { key: 'hits', label: t('ai_learned_intents.stat_hits'), value: stats.value?.hits ?? 0, icon: 'ri-flashlight-line', tone: 'bg-info-transparent text-info', hint: t('ai_learned_intents.stat_hits_hint') },
]);

const selectedCount = computed(() => selectedIds.value.length);
const allSelected = computed(() => items.value.length > 0 && items.value.every((row) => selectedIds.value.includes(Number(row.id))));

function isSelected(id) {
    return selectedIds.value.includes(Number(id));
}

function intentLabel(intent) {
    return t(`ai_learned_intents.intents.${intent}`);
}

function intentIcon(intent) {
    return INTENT_ICONS[intent] ?? 'ri-question-line';
}

function stateTone(state) {
    return { active: 'bg-success-transparent', pending: 'bg-warning-transparent', conflict: 'bg-danger-transparent' }[state] ?? 'bg-light';
}

function stageTone(stage) {
    return {
        lexicon: 'bg-success-transparent',
        learned: 'bg-success-transparent',
        would_ask_model: 'bg-warning-transparent',
        skipped: 'bg-light text-muted',
        router_disabled: 'bg-danger-transparent',
    }[stage] ?? 'bg-light';
}

function confidenceTone(value) {
    if (value >= 0.9) return 'bg-success';
    if (value >= 0.7) return 'bg-warning';
    return 'bg-danger';
}

async function loadStats() {
    statsLoading.value = true;

    try {
        const { data } = await adminAxios.get(`${BASE}/stats`);
        stats.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        statsLoading.value = false;
    }
}

function refreshAll(page = currentPage.value) {
    loadStats();
    fetchItems(page);
}

function applyFilters() {
    fetchItems(1);
}

function changePage(page) {
    if (pagination.value && page >= 1 && page <= pagination.value.last_page) {
        fetchItems(page);
    }
}

// The switch follows the router's rule: off = "do not trust" (conflict), on = active (AiLearnedIntentRepository).
async function toggleRow(record) {
    const before = record.status;
    await toggleStatus(record);

    if (record.status === before) {
        return; // the request failed and the switch went back
    }

    record.state = record.status ? 'active' : 'conflict';
    record.conflicts = record.status ? 0 : Math.max(1, record.conflicts ?? 0);
    loadStats();
}

async function runTest() {
    if (! testMessage.value.trim()) return;

    testing.value = true;

    try {
        const { data } = await adminAxios.post(`${BASE}/test`, { message: testMessage.value, recent_image: testRecentImage.value });
        testResult.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        testing.value = false;
    }
}

function openCreate() {
    modalType.value = 'create';
    selectedRecord.value = null;
    modalShow.value = true;
}

function openEdit(record) {
    modalType.value = 'edit';
    selectedRecord.value = { ...record };
    modalShow.value = true;
}

const {
    confirmDelete,
    confirmDeleteSelected,
    confirmForceDelete,
    confirmForceDeleteSelected,
    confirmRestore,
    handleDeleteConfirm,
} = useCatalogTrashActions({
    t,
    deleteConfirm,
    deleteSelected: catalog.deleteSelected,
    deleteItem: catalog.deleteItem,
    restoreItem: catalog.restoreItem,
    forceDeleteItem: catalog.forceDeleteItem,
    forceDeleteSelected: catalog.forceDeleteSelected,
    i18nPrefix: 'ai_learned_intents',
});

async function onDeleteConfirm() {
    await handleDeleteConfirm();
    loadStats();
}

function onSaved() {
    modalShow.value = false;
    refreshAll();
}

onMounted(() => refreshAll(1));
</script>
