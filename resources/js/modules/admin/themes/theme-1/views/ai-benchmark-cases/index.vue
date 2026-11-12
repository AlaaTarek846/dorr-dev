<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <h1 class="page-title fw-semibold fs-18 mb-0">
                    {{ t('ai_benchmark_cases.title') }}
                    <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ pagination.total }}</span>
                </h1>
                <span class="fs-semibold text-muted">{{ t('ai_benchmark_cases.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.ai-benchmark-runs.index' }">{{ t('ai_benchmark_runs.title') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_benchmark_cases.title') }}</li>
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
                                <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                                <input v-model="search" type="search" class="form-control" :placeholder="t('ai_benchmark_cases.search')">
                                <button v-if="search" type="button" class="btn btn-light border catalog-search-clear" :title="t('ai_benchmark_cases.clear_search')" @click="search = ''">
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button v-if="statusFilter !== 'all'" type="button" class="btn btn-sm catalog-filter-btn catalog-filter-btn--all-idle" @click="setStatusFilter('all')">
                                {{ t('ai_benchmark_cases.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('ai_benchmark_cases.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('ai_benchmark_cases.filter_inactive') }} ({{ counts.inactive }})
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
                                <i class="ri-add-line me-1 align-middle"></i>{{ t('ai_benchmark_cases.add_short') }}
                            </button>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th v-if="canMultipleDelete" scope="col" class="ps-4" style="width: 48px;">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="allSelected"
                                                :disabled="loading || !items.length"
                                                :aria-label="t('ai_benchmark_cases.select_all')"
                                                @change="toggleSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('ai_benchmark_cases.prompt') }}</th>
                                        <th scope="col">{{ t('ai_benchmark_cases.domain') }}</th>
                                        <th scope="col">{{ t('ai_benchmark_cases.difficulty') }}</th>
                                        <th scope="col">{{ t('ai_benchmark_cases.expected_behavior') }}</th>
                                        <th scope="col">{{ t('ai_benchmark_cases.results_count') }}</th>
                                        <th scope="col">{{ t('ai_benchmark_cases.status') }}</th>
                                        <th scope="col">{{ t('ai_benchmark_cases.created_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_benchmark_cases.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="tableColumnCount" />

                                    <tr v-else-if="!items.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3"><i class="ri-test-tube-line fs-2 text-primary"></i></span>
                                                <p class="fw-semibold mb-1">{{ t('ai_benchmark_cases.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('ai_benchmark_cases.empty') }}</p>
                                                <button v-if="canCreate && !isDeletedView" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>{{ t('ai_benchmark_cases.add_short') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr v-for="item in items" :key="item.id">
                                            <td v-if="canMultipleDelete" class="ps-4">
                                                <input class="form-check-input" type="checkbox" :checked="isSelected(item.id)" :aria-label="item.prompt" @change="toggleSelect(item.id, $event.target.checked)">
                                            </td>
                                            <td class="text-wrap" style="max-width: 420px;">
                                                <button v-if="canUpdate && !isTrashedRecord(item)" type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click="openEdit(item)">
                                                    {{ item.prompt }}
                                                </button>
                                                <span v-else class="fw-semibold text-default">{{ item.prompt }}</span>
                                                <span class="d-block text-muted fs-11">#{{ item.id }} · {{ item.language || '-' }}<template v-if="item.country_code"> · {{ item.country_code }}</template> · {{ item.task_type || '-' }}</span>
                                            </td>
                                            <td dir="ltr">{{ item.domain_key || '-' }}</td>
                                            <td>
                                                <span class="badge" :class="difficultyBadgeClass(item.difficulty)">{{ t('ai_benchmark_cases.difficulty_' + item.difficulty) }}</span>
                                            </td>
                                            <td class="text-wrap">{{ t('ai_benchmark_cases.behavior_' + item.expected_behavior) }}</td>
                                            <td><span class="badge bg-primary-transparent">{{ item.results_count ?? 0 }}</span></td>
                                            <td>
                                                <span v-if="isTrashedRecord(item)" class="badge bg-danger-transparent">{{ t('catalog.deleted_badge') }}</span>
                                                <div
                                                    v-else-if="canChangeStatus"
                                                    class="toggle toggle-success mb-0 catalog-status-toggle"
                                                    :class="{ on: item.status, 'catalog-status-toggle--loading': isTogglingStatus(item.id) }"
                                                    role="switch"
                                                    tabindex="0"
                                                    :aria-checked="item.status"
                                                    :aria-busy="isTogglingStatus(item.id)"
                                                    @click="toggleStatus(item)"
                                                    @keydown.enter.space.prevent="toggleStatus(item)"
                                                >
                                                    <span></span>
                                                </div>
                                                <span v-else class="badge" :class="item.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                    {{ item.status ? t('ai_benchmark_cases.filter_active') : t('ai_benchmark_cases.filter_inactive') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatCatalogDate(catalogPrimaryDate(item), locale) }}</span>
                                                <span v-if="catalogShowUpdatedSubtext(item)" class="d-block text-muted fs-11">{{ t('ai_benchmark_cases.updated') }}: {{ formatCatalogDate(item.updated_at, locale) }}</span>
                                                <span v-if="catalogShowDeletedSubtext(item, isDeletedView)" class="d-block text-muted fs-11">{{ t('catalog.deleted_at') }}: {{ formatCatalogDate(item.deleted_at, locale) }}</span>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div v-if="isTrashedRecord(item)" class="btn-list justify-content-end">
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-success-light btn-icon" :title="t('catalog.restore_title')" @click="confirmRestore(item.id)">
                                                        <i class="ri-arrow-go-back-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('catalog.force_delete_title')" @click="confirmForceDelete(item.id)">
                                                        <i class="ri-delete-bin-7-line"></i>
                                                    </button>
                                                </div>
                                                <div v-else class="btn-list justify-content-end">
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('ai_benchmark_cases.edit_title')" @click="openEdit(item)">
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('ai_benchmark_cases.delete_title')" @click="confirmDelete(item.id)">
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

        <ModalCreateAndUpdate :show="modalShow" :type="modalType" :record="selectedRecord" @close="modalShow = false" @saved="onSaved" />

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
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import ConfirmDeleteModal from '../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import { useAiBenchmarkCases } from '../../../../../../composables/useAiQualityScreens';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useCatalogTrashActions } from '../../../../../../composables/useCatalogTrashActions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useAiBenchmarkCasesStore } from '../../../../../../stores/aiQualityScreens';
import {
    catalogPrimaryDate,
    catalogShowDeletedSubtext,
    catalogShowUpdatedSubtext,
    formatCatalogDate,
    isTrashedRecord,
} from '../../../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();

const countsStore = useAiBenchmarkCasesStore();
const { canCreate, canUpdate, canDelete, canChangeStatus, canMultipleDelete } = useCatalogPermissions('ai-benchmark-cases');

const catalog = useAiBenchmarkCases();
const { items, loading, pagination, selectedIds, currentPage, perPage, search, statusFilter, isDeletedView } = catalog;
const { fetchItems, setStatusFilter, toggleStatus, toggleSelectAll, toggleSelect, isTogglingStatus } = catalog;

const tableColumnCount = computed(() => 8 + (canMultipleDelete.value ? 1 : 0));

const counts = computed(() => ({
    total: countsStore.total ?? pagination.value?.total ?? 0,
    active: countsStore.activeCount ?? 0,
    inactive: countsStore.inactiveCount ?? 0,
    deleted: countsStore.deletedCount ?? 0,
}));

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const deleteConfirm = useConfirmDelete();

const selectedCount = computed(() => selectedIds.value.length);
const allSelected = computed(() => items.value.length > 0 && items.value.every((row) => selectedIds.value.includes(Number(row.id))));

function isSelected(id) {
    return selectedIds.value.includes(Number(id));
}

function difficultyBadgeClass(difficulty) {
    return {
        easy: 'bg-success-transparent',
        hard: 'bg-info-transparent',
        adversarial: 'bg-warning-transparent',
        insufficient_info: 'bg-danger-transparent',
    }[difficulty] || 'bg-light text-muted';
}

function changePage(page) {
    if (pagination.value && page >= 1 && page <= pagination.value.last_page) {
        fetchItems(page);
    }
}

function openCreate() {
    modalType.value = 'create';
    selectedRecord.value = null;
    modalShow.value = true;
}

function openEdit(item) {
    modalType.value = 'edit';
    selectedRecord.value = { ...item };
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
    i18nPrefix: 'ai_benchmark_cases',
});

function onSaved() {
    modalShow.value = false;
    fetchItems(currentPage.value);
}

onMounted(() => fetchItems());
</script>
