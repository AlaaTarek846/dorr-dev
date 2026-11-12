<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <h1 class="page-title fw-semibold fs-18 mb-0">
                    {{ t('ai_feature_flags.title') }}
                    <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ pagination.total }}</span>
                </h1>
                <span class="fs-semibold text-muted">{{ t('ai_feature_flags.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_feature_flags.title') }}</li>
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
                                <input v-model="search" type="search" class="form-control" :placeholder="t('ai_feature_flags.search')">
                                <button v-if="search" type="button" class="btn btn-light border catalog-search-clear" :title="t('ai_feature_flags.clear_search')" @click="search = ''">
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button v-if="statusFilter !== 'all'" type="button" class="btn btn-sm catalog-filter-btn catalog-filter-btn--all-idle" @click="setStatusFilter('all')">
                                {{ t('ai_feature_flags.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('ai_feature_flags.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('ai_feature_flags.filter_inactive') }} ({{ counts.inactive }})
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
                                <i class="ri-add-line me-1 align-middle"></i>{{ t('ai_feature_flags.add_short') }}
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
                                                :aria-label="t('ai_feature_flags.select_all')"
                                                @change="toggleSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('ai_feature_flags.key') }}</th>
                                        <th scope="col">{{ t('ai_feature_flags.target') }}</th>
                                        <th scope="col">{{ t('ai_feature_flags.scope') }}</th>
                                        <th scope="col">{{ t('ai_feature_flags.environment') }}</th>
                                        <th scope="col">{{ t('ai_feature_flags.status') }}</th>
                                        <th scope="col">{{ t('ai_feature_flags.created_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_feature_flags.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="tableColumnCount" />

                                    <tr v-else-if="!items.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3"><i class="ri-toggle-line fs-2 text-primary"></i></span>
                                                <p class="fw-semibold mb-1">{{ t('ai_feature_flags.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('ai_feature_flags.empty') }}</p>
                                                <button v-if="canCreate && !isDeletedView" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>{{ t('ai_feature_flags.add_short') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr v-for="flag in items" :key="flag.id">
                                            <td v-if="canMultipleDelete" class="ps-4">
                                                <input class="form-check-input" type="checkbox" :checked="isSelected(flag.id)" :aria-label="flag.key" @change="toggleSelect(flag.id, $event.target.checked)">
                                            </td>
                                            <td class="text-wrap" style="max-width: 320px;">
                                                <button v-if="canUpdate && !isTrashedRecord(flag)" type="button" class="btn btn-link p-0 text-start fw-semibold text-default" dir="ltr" @click="openEdit(flag)">
                                                    {{ flag.key }}
                                                </button>
                                                <span v-else class="fw-semibold text-default" dir="ltr">{{ flag.key }}</span>
                                                <span v-if="flag.description" class="d-block text-muted fs-11">{{ flag.description }}</span>
                                                <span class="d-block text-muted fs-11">#{{ flag.id }}</span>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary-transparent">{{ t('ai_feature_flags.target_type_' + flag.target_type) }}</span>
                                                <span class="d-block text-muted fs-11 mt-1" dir="ltr">{{ targetLabel(flag) }}</span>
                                            </td>
                                            <td>{{ flag.country_code || t('ai_feature_flags.all_countries') }} / {{ flag.domain || t('ai_feature_flags.all_domains') }}</td>
                                            <td><span class="badge bg-light text-default" dir="ltr">{{ flag.environment }}</span></td>
                                            <td>
                                                <span v-if="isTrashedRecord(flag)" class="badge bg-danger-transparent">{{ t('catalog.deleted_badge') }}</span>
                                                <div
                                                    v-else-if="canChangeStatus"
                                                    class="toggle toggle-success mb-0 catalog-status-toggle"
                                                    :class="{ on: flag.status, 'catalog-status-toggle--loading': isTogglingStatus(flag.id) }"
                                                    role="switch"
                                                    tabindex="0"
                                                    :aria-checked="flag.status"
                                                    :aria-busy="isTogglingStatus(flag.id)"
                                                    :title="flag.status ? t('ai_feature_flags.status_on_hint') : t('ai_feature_flags.status_off_hint')"
                                                    @click="toggleStatus(flag)"
                                                    @keydown.enter.space.prevent="toggleStatus(flag)"
                                                >
                                                    <span></span>
                                                </div>
                                                <span v-else class="badge" :class="flag.status ? 'bg-success-transparent' : 'bg-danger-transparent'">
                                                    {{ flag.status ? t('ai_feature_flags.filter_active') : t('ai_feature_flags.filter_inactive') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatCatalogDate(catalogPrimaryDate(flag), locale) }}</span>
                                                <span v-if="catalogShowUpdatedSubtext(flag)" class="d-block text-muted fs-11">{{ t('ai_feature_flags.updated') }}: {{ formatCatalogDate(flag.updated_at, locale) }}</span>
                                                <span v-if="catalogShowDeletedSubtext(flag, isDeletedView)" class="d-block text-muted fs-11">{{ t('catalog.deleted_at') }}: {{ formatCatalogDate(flag.deleted_at, locale) }}</span>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div v-if="isTrashedRecord(flag)" class="btn-list justify-content-end">
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-success-light btn-icon" :title="t('catalog.restore_title')" @click="confirmRestore(flag.id)">
                                                        <i class="ri-arrow-go-back-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('catalog.force_delete_title')" @click="confirmForceDelete(flag.id)">
                                                        <i class="ri-delete-bin-7-line"></i>
                                                    </button>
                                                </div>
                                                <div v-else class="btn-list justify-content-end">
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('ai_feature_flags.edit_title')" @click="openEdit(flag)">
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('ai_feature_flags.delete_title')" @click="confirmDelete(flag.id)">
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
import { useAiFeatureFlags } from '../../../../../../composables/useAiControlScreens';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useCatalogTrashActions } from '../../../../../../composables/useCatalogTrashActions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useAiFeatureFlagsStore } from '../../../../../../stores/aiControlScreens';
import {
    catalogPrimaryDate,
    catalogShowDeletedSubtext,
    catalogShowUpdatedSubtext,
    formatCatalogDate,
    isTrashedRecord,
} from '../../../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();

const countsStore = useAiFeatureFlagsStore();
const { canCreate, canUpdate, canDelete, canChangeStatus, canMultipleDelete } = useCatalogPermissions('ai-feature-flags');

const catalog = useAiFeatureFlags();
const { items, loading, pagination, selectedIds, currentPage, perPage, search, statusFilter, isDeletedView } = catalog;
const { fetchItems, setStatusFilter, toggleStatus, toggleSelectAll, toggleSelect, isTogglingStatus } = catalog;

const tableColumnCount = computed(() => 7 + (canMultipleDelete.value ? 1 : 0));

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

/** What the flag aims at: the provider, then the model / tool / service key ("-" = every one of its type). */
function targetLabel(flag) {
    if (flag.target_type === 'service') {
        return flag.tool_key === 'ai_chat' ? t('ai_feature_flags.service_ai_chat') : (flag.tool_key || '-');
    }

    return [flag.provider?.name, flag.model_key, flag.tool_key].filter(Boolean).join(' · ') || '-';
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

function openEdit(flag) {
    modalType.value = 'edit';
    selectedRecord.value = { ...flag };
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
    i18nPrefix: 'ai_feature_flags',
});

function onSaved() {
    modalShow.value = false;
    fetchItems(currentPage.value);
}

onMounted(() => fetchItems());
</script>
