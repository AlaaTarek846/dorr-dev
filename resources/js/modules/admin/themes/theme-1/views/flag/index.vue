<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('flags.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('flags.title') }}</li>
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
                                <input
                                    v-model="search"
                                    type="search"
                                    class="form-control"
                                    :placeholder="t('flags.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('flags.clear_search')"
                                    @click="clearSearch"
                                >
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button
                                v-if="showAllFilter"
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'all' ? 'catalog-filter-btn--all' : 'catalog-filter-btn--all-idle'"
                                @click="setStatusFilter('all')"
                            >
                                {{ t('flags.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('flags.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('flags.filter_inactive') }} ({{ counts.inactive }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'deleted' ? 'catalog-filter-btn--deleted' : 'catalog-filter-btn--deleted-idle'"
                                @click="setStatusFilter('deleted')"
                            >
                                {{ t('catalog.filter_deleted') }} ({{ counts.deleted }})
                            </button>
                        </div>

                        <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                            <button
                                v-if="canMultipleDelete && selectedCount && statusFilter !== 'deleted'"
                                type="button"
                                class="btn btn-danger btn-sm btn-wave"
                                @click="confirmDeleteSelected"
                            >
                                <i class="ri-delete-bin-line me-1 align-middle"></i>
                                {{ t('catalog.bulk_delete_count', { count: selectedCount }) }}
                            </button>
                            <button
                                v-if="canDelete && selectedCount && statusFilter === 'deleted'"
                                type="button"
                                class="btn btn-danger btn-sm btn-wave"
                                @click="confirmForceDeleteSelected"
                            >
                                <i class="ri-delete-bin-7-line me-1 align-middle"></i>
                                {{ t('catalog.force_delete_count', { count: selectedCount }) }}
                            </button>
                            <button
                                v-if="canCreate && statusFilter !== 'deleted'"
                                type="button"
                                class="btn btn-primary btn-sm btn-wave"
                                @click="openCreate"
                            >
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ t('flags.add_short') }}
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
                                                :disabled="loading || !flags.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('flags.name') }}</th>
                                        <th scope="col">{{ t('flags.code') }}</th>
                                        <th scope="col">{{ t('flags.status') }}</th>
                                        <th scope="col">{{ t('flags.created_at') }}</th>
                                        <th v-if="showActionsColumn" scope="col" class="text-end pe-4">{{ t('flags.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="tableColumnCount" />

                                    <tr v-else-if="!flags.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-flag-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('flags.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('flags.empty') }}</p>
                                                <button v-if="canCreate && !isDeletedView" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('flags.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                    <tr
                                        v-for="flag in flags"
                                        :key="flag.id"
                                        class="crm-contact"
                                    >
                                        <td v-if="canMultipleDelete" class="ps-4">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="isSelected(flag.id)"
                                                @change="onRowSelect(flag.id, $event.target.checked)"
                                            >
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <FlagImage
                                                    :code="flag.code"
                                                    :width="32"
                                                    :height="24"
                                                    :size="32"
                                                />
                                                <div>
                                                    <button
                                                        v-if="canUpdate && !isTrashedRecord(flag)"
                                                        type="button"
                                                        class="btn btn-link p-0 text-start fw-semibold text-default"
                                                        @click="openEdit(flag)"
                                                    >
                                                        {{ displayName(flag) }}
                                                    </button>
                                                    <span v-else class="fw-semibold text-default">{{ displayName(flag) }}</span>
                                                    <span class="d-block text-muted fs-11">
                                                        #{{ flag.id }}
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-transparent">{{ flag.code }}</span>
                                        </td>
                                        <td>
                                            <span v-if="isTrashedRecord(flag)" class="badge bg-danger-transparent">
                                                {{ t('catalog.deleted_badge') }}
                                            </span>
                                            <div
                                                v-else-if="canChangeStatus"
                                                class="toggle toggle-success mb-0 catalog-status-toggle"
                                                :class="{
                                                    on: flag.status,
                                                    'catalog-status-toggle--loading': isTogglingStatus(flag.id),
                                                }"
                                                role="button"
                                                tabindex="0"
                                                :aria-busy="isTogglingStatus(flag.id)"
                                                @click="toggleStatus(flag)"
                                                @keydown.enter.space.prevent="toggleStatus(flag)"
                                            >
                                                <span></span>
                                            </div>
                                            <span v-else class="badge" :class="flag.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                {{ flag.status ? t('flags.active') : t('flags.inactive') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ formatDate(catalogPrimaryDate(flag)) }}</span>
                                            <span v-if="catalogShowUpdatedSubtext(flag)" class="d-block text-muted fs-11">
                                                {{ t('flags.updated') }}: {{ formatDate(flag.updated_at) }}
                                            </span>
                                            <span v-if="catalogShowDeletedSubtext(flag, isDeletedView)" class="d-block text-muted fs-11">
                                                {{ t('catalog.deleted_at') }}: {{ formatDate(flag.deleted_at) }}
                                            </span>
                                        </td>
                                        <td v-if="showActionsColumn" class="text-end pe-4">
                                            <div v-if="isTrashedRecord(flag)" class="btn-list justify-content-end">
                                                <button
                                                    v-if="canUpdate"
                                                    type="button"
                                                    class="btn btn-sm btn-success-light btn-icon"
                                                    :title="t('catalog.restore_title')"
                                                    @click="confirmRestore(flag.id)"
                                                >
                                                    <i class="ri-arrow-go-back-line"></i>
                                                </button>
                                                <button
                                                    v-if="canDelete"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('catalog.force_delete_title')"
                                                    @click="confirmForceDelete(flag.id)"
                                                >
                                                    <i class="ri-delete-bin-7-line"></i>
                                                </button>
                                            </div>
                                            <div v-else-if="!isTrashedRecord(flag)" class="btn-list justify-content-end">
                                                <button
                                                    v-if="canUpdate"
                                                    type="button"
                                                    class="btn btn-sm btn-info-light btn-icon"
                                                    :title="t('flags.edit_title')"
                                                    @click="openEdit(flag)"
                                                >
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button
                                                    v-if="canDelete"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('flags.confirm_delete')"
                                                    @click="confirmDelete(flag.id)"
                                                >
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

                    <div v-if="pagination && !loading" class="card-footer border-top-0">
                        <div class="d-flex align-items-center flex-wrap gap-3">
                            <div class="d-flex align-items-center gap-2 text-muted fs-13">
                                <span>{{ entriesLabel }}</span>
                                <i :class="paginationArrowIcon"></i>
                            </div>

                            <div class="d-flex align-items-center gap-2 ms-md-auto">
                                <label class="text-muted fs-13 mb-0" for="flags-per-page">{{ t('flags.per_page') }}</label>
                                <select
                                    id="flags-per-page"
                                    v-model.number="perPage"
                                    class="form-select form-select-sm w-auto"
                                >
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="Flags pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('flags.previous') }}
                                        </button>
                                    </li>
                                    <li
                                        v-for="page in pageNumbers"
                                        :key="page"
                                        class="page-item"
                                        :class="{ active: page === currentPage }"
                                    >
                                        <button type="button" class="page-link" @click="changePage(page)">
                                            {{ page }}
                                        </button>
                                    </li>
                                    <li class="page-item" :class="{ disabled: !pagination.next_page_url }">
                                        <button type="button" class="page-link text-primary" @click="changePage(currentPage + 1)">
                                            {{ t('flags.next') }}
                                        </button>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ModalCreateAndUpdate
            :show="modalShow"
            :type="modalType"
            :record="selectedRecord"
            @close="modalShow = false"
            @saved="onSaved"
        />

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
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import ConfirmDeleteModal from '../../../../../../components/ui/ConfirmDeleteModal.vue';
import FlagImage from '../../../../../../components/ui/FlagImage.vue';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import { useCatalogTrashActions } from '../../../../../../composables/useCatalogTrashActions';
import {
    catalogPrimaryDate,
    catalogShowDeletedSubtext,
    catalogShowUpdatedSubtext,
    isTrashedRecord,
} from '../../../../../../utils/catalog';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useFlags } from '../../../../../../composables/useFlags';
import { useFlagsStore } from '../../../../../../stores/flags';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const flagsStore = useFlagsStore();

const {
    canCreate,
    canUpdate,
    canDelete,
    canChangeStatus,
    canMultipleDelete,
    showActionsColumn,
} = useCatalogPermissions('flags');

const tableColumnCount = computed(() => {
    let count = 4;

    if (canMultipleDelete.value) {
        count += 1;
    }

    if (showActionsColumn.value) {
        count += 1;
    }

    return count;
});

const flagsApi = useFlags();
const {
    flags,
    loading,
    pagination,
    selectedIds,
    currentPage,
    perPage,
    search,
    statusFilter,
    isDeletedView,
} = storeToRefs(flagsApi);
const {
    fetchFlags,
    setStatusFilter,
    deleteFlag,
    deleteSelected,
    restoreFlag,
    forceDeleteFlag,
    forceDeleteSelected,
    toggleStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = flagsApi;

const counts = computed(() => ({
    total: flagsStore.total ?? pagination.value?.total ?? 0,
    active: flagsStore.activeCount ?? 0,
    inactive: flagsStore.inactiveCount ?? 0,
    deleted: flagsStore.deletedCount ?? 0,
}));

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const deleteConfirm = useConfirmDelete();

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
    deleteSelected,
    deleteItem: deleteFlag,
    restoreItem: restoreFlag,
    forceDeleteItem: forceDeleteFlag,
    forceDeleteSelected,
    i18nPrefix: 'flags',
});

const selectedCount = computed(() => selectedIds.value.length);

const showAllFilter = computed(() => statusFilter.value !== 'all');

const paginationArrowIcon = computed(() => (
    locale.value === 'ar'
        ? 'ri-arrow-left-s-line fw-semibold'
        : 'ri-arrow-right-s-line fw-semibold'
));

const allSelected = computed(() => {
    if (! flags.value.length) {
        return false;
    }

    return flags.value.every((flag) => selectedIds.value.includes(Number(flag.id)));
});

function isSelected(id) {
    return selectedIds.value.includes(Number(id));
}

function onRowSelect(id, checked) {
    toggleSelect(id, checked);
}

function onSelectAll(checked) {
    toggleSelectAll(checked);
}

const pageNumbers = computed(() => {
    if (! pagination.value?.last_page) {
        return [];
    }

    const total = pagination.value.last_page;
    const current = currentPage.value;
    const pages = [];

    let start = Math.max(1, current - 2);
    let end = Math.min(total, start + 4);

    if (end - start < 4) {
        start = Math.max(1, end - 4);
    }

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    return pages;
});

const entriesLabel = computed(() => {
    if (! pagination.value) {
        return '';
    }

    return t('flags.showing_entries', {
        from: pagination.value.from ?? 0,
        to: pagination.value.to ?? 0,
        total: pagination.value.total ?? 0,
    });
});

function displayName(flag) {
    const translation = flag.translations?.find((item) => item.locale === locale.value);

    return translation?.name || flag.name || '-';
}

function formatDate(value) {
    if (! value) {
        return '-';
    }

    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function clearSearch() {
    search.value = '';
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

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchFlags(page);
}

function onSaved() {
    modalShow.value = false;
    fetchFlags(currentPage.value);
}

onMounted(() => {
    fetchFlags();
});
</script>

<style scoped>
.flag-img {
    display: block;
    object-fit: cover;
    border-radius: 2px;
    flex-shrink: 0;
}
</style>
