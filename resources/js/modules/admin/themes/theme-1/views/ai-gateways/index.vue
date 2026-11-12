<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <h1 class="page-title fw-semibold fs-18 mb-0">
                    {{ t('ai_gateways.title') }}
                    <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ pagination.total }}</span>
                </h1>
                <span class="fs-semibold text-muted">{{ t('ai_gateways.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_gateways.title') }}</li>
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
                                <input v-model="search" type="search" class="form-control" :placeholder="t('ai_gateways.search')">
                                <button v-if="search" type="button" class="btn btn-light border catalog-search-clear" :title="t('ai_gateways.clear_search')" @click="search = ''">
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button v-if="statusFilter !== 'all'" type="button" class="btn btn-sm catalog-filter-btn catalog-filter-btn--all-idle" @click="setStatusFilter('all')">
                                {{ t('ai_gateways.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('ai_gateways.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('ai_gateways.filter_inactive') }} ({{ counts.inactive }})
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
                                <i class="ri-add-line me-1 align-middle"></i>{{ t('ai_gateways.add_short') }}
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
                                                :aria-label="t('ai_gateways.select_all')"
                                                @change="toggleSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('ai_gateways.name') }}</th>
                                        <th scope="col">{{ t('ai_gateways.environment') }}</th>
                                        <th scope="col">{{ t('ai_gateways.default_policy') }}</th>
                                        <th scope="col">{{ t('ai_gateways.status') }}</th>
                                        <th scope="col">{{ t('ai_gateways.created_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_gateways.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="tableColumnCount" />

                                    <tr v-else-if="!items.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3"><i class="ri-door-open-line fs-2 text-primary"></i></span>
                                                <p class="fw-semibold mb-1">{{ t('ai_gateways.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('ai_gateways.empty') }}</p>
                                                <button v-if="canCreate && !isDeletedView" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>{{ t('ai_gateways.add_short') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr v-for="gateway in items" :key="gateway.id">
                                            <td v-if="canMultipleDelete" class="ps-4">
                                                <input class="form-check-input" type="checkbox" :checked="isSelected(gateway.id)" :aria-label="gateway.name" @change="toggleSelect(gateway.id, $event.target.checked)">
                                            </td>
                                            <td>
                                                <button v-if="canUpdate && !isTrashedRecord(gateway)" type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click="openEdit(gateway)">
                                                    {{ gateway.name }}
                                                </button>
                                                <span v-else class="fw-semibold text-default">{{ gateway.name }}</span>
                                                <span class="d-block text-muted fs-11">#{{ gateway.id }}</span>
                                            </td>
                                            <td><span class="badge" :class="gateway.environment === 'production' ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ environmentLabel(gateway.environment) }}</span></td>
                                            <td>
                                                <span>{{ gateway.default_policy?.name || '—' }}</span>
                                                <span v-if="gateway.default_policy?.deleted" class="badge bg-danger-transparent ms-1">{{ t('catalog.deleted_badge') }}</span>
                                                <span v-else-if="gateway.default_policy && !gateway.default_policy.status" class="badge bg-secondary-transparent ms-1">{{ t('ai_gateways.filter_inactive') }}</span>
                                            </td>
                                            <td>
                                                <span v-if="isTrashedRecord(gateway)" class="badge bg-danger-transparent">{{ t('catalog.deleted_badge') }}</span>
                                                <div
                                                    v-else-if="canChangeStatus"
                                                    class="toggle toggle-success mb-0 catalog-status-toggle"
                                                    :class="{ on: gateway.status, 'catalog-status-toggle--loading': isTogglingStatus(gateway.id) }"
                                                    role="switch"
                                                    tabindex="0"
                                                    :aria-checked="gateway.status"
                                                    :aria-busy="isTogglingStatus(gateway.id)"
                                                    @click="toggleStatus(gateway)"
                                                    @keydown.enter.space.prevent="toggleStatus(gateway)"
                                                >
                                                    <span></span>
                                                </div>
                                                <span v-else class="badge" :class="gateway.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                    {{ gateway.status ? t('ai_gateways.filter_active') : t('ai_gateways.filter_inactive') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatCatalogDate(catalogPrimaryDate(gateway), locale) }}</span>
                                                <span v-if="catalogShowUpdatedSubtext(gateway)" class="d-block text-muted fs-11">{{ t('ai_gateways.updated') }}: {{ formatCatalogDate(gateway.updated_at, locale) }}</span>
                                                <span v-if="catalogShowDeletedSubtext(gateway, isDeletedView)" class="d-block text-muted fs-11">{{ t('catalog.deleted_at') }}: {{ formatCatalogDate(gateway.deleted_at, locale) }}</span>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div v-if="isTrashedRecord(gateway)" class="btn-list justify-content-end">
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-success-light btn-icon" :title="t('catalog.restore_title')" @click="confirmRestore(gateway.id)">
                                                        <i class="ri-arrow-go-back-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('catalog.force_delete_title')" @click="confirmForceDelete(gateway.id)">
                                                        <i class="ri-delete-bin-7-line"></i>
                                                    </button>
                                                </div>
                                                <div v-else class="btn-list justify-content-end">
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('ai_gateways.edit_title')" @click="openEdit(gateway)">
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('ai_gateways.delete_title')" @click="confirmDelete(gateway.id)">
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
import { useAiGateways } from '../../../../../../composables/useAiRoutingScreens';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useCatalogTrashActions } from '../../../../../../composables/useCatalogTrashActions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useAiGatewaysStore } from '../../../../../../stores/aiRoutingScreens';
import {
    catalogPrimaryDate,
    catalogShowDeletedSubtext,
    catalogShowUpdatedSubtext,
    formatCatalogDate,
    isTrashedRecord,
} from '../../../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();

const countsStore = useAiGatewaysStore();
const { canCreate, canUpdate, canDelete, canChangeStatus, canMultipleDelete } = useCatalogPermissions('ai-gateways');

const catalog = useAiGateways();
const { items, loading, pagination, selectedIds, currentPage, perPage, search, statusFilter, isDeletedView } = catalog;
const { fetchItems, setStatusFilter, toggleStatus, toggleSelectAll, toggleSelect, isTogglingStatus } = catalog;

const tableColumnCount = computed(() => 6 + (canMultipleDelete.value ? 1 : 0));

const ENVIRONMENTS = ['production', 'staging', 'development'];

function environmentLabel(value) {
    return ENVIRONMENTS.includes(value) ? t(`ai_gateways.env_${value}`) : value;
}

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

function openEdit(gateway) {
    modalType.value = 'edit';
    selectedRecord.value = { ...gateway };
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
    i18nPrefix: 'ai_gateways',
});

function onSaved() {
    modalShow.value = false;
    fetchItems(currentPage.value);
}

onMounted(() => fetchItems());
</script>
