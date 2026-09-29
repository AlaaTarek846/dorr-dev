<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('mobile_app_fonts.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('mobile_app_fonts.title') }}</li>
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
                                    :placeholder="t('mobile_app_fonts.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('mobile_app_fonts.clear_search')"
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
                                {{ t('mobile_app_fonts.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('mobile_app_fonts.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('mobile_app_fonts.filter_inactive') }} ({{ counts.inactive }})
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
                                {{ t('mobile_app_fonts.add_short') }}
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
                                                :disabled="loading || !mobileAppFonts.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('mobile_app_fonts.name') }}</th>
                                        <th scope="col">{{ t('mobile_app_fonts.slug') }}</th>
                                        <th scope="col">{{ t('mobile_app_fonts.files') }}</th>
                                        <th scope="col">{{ t('mobile_app_fonts.status') }}</th>
                                        <th scope="col">{{ t('mobile_app_fonts.created_at') }}</th>
                                        <th v-if="showActionsColumn" scope="col" class="text-end pe-4">{{ t('mobile_app_fonts.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="tableColumnCount" />

                                    <tr v-else-if="!mobileAppFonts.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-font-family fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('mobile_app_fonts.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('mobile_app_fonts.empty') }}</p>
                                                <button v-if="canCreate && !isDeletedView" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('mobile_app_fonts.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                    <tr
                                        v-for="font in mobileAppFonts"
                                        :key="font.id"
                                        class="crm-contact"
                                    >
                                        <td v-if="canMultipleDelete" class="ps-4">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="isSelected(font.id)"
                                                @change="onRowSelect(font.id, $event.target.checked)"
                                            >
                                        </td>
                                        <td>
                                            <div>
                                                <button
                                                    v-if="canUpdate && !isTrashedRecord(font)"
                                                    type="button"
                                                    class="btn btn-link p-0 text-start fw-semibold text-default"
                                                    @click="openEdit(font)"
                                                >
                                                    {{ displayName(font) }}
                                                </button>
                                                <span v-else class="fw-semibold text-default">{{ displayName(font) }}</span>
                                                <span v-if="font.is_default" class="badge bg-info-transparent ms-1">{{ t('mobile_app_fonts.default_badge') }}</span>
                                                <span class="d-block text-muted fs-11">
                                                    #{{ font.id }}
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-transparent">{{ font.slug }}</span>
                                        </td>
                                        <td>
                                            <span class="text-muted">{{ font.font_files?.length ?? 0 }}</span>
                                        </td>
                                        <td>
                                            <span v-if="isTrashedRecord(font)" class="badge bg-danger-transparent">
                                                {{ t('catalog.deleted_badge') }}
                                            </span>
                                            <div
                                                v-else-if="canChangeStatus"
                                                class="toggle toggle-success mb-0 catalog-status-toggle"
                                                :class="{
                                                    on: font.status,
                                                    'catalog-status-toggle--loading': isTogglingStatus(font.id),
                                                }"
                                                role="button"
                                                tabindex="0"
                                                :aria-busy="isTogglingStatus(font.id)"
                                                @click="toggleStatus(font)"
                                                @keydown.enter.space.prevent="toggleStatus(font)"
                                            >
                                                <span></span>
                                            </div>
                                            <span v-else class="badge" :class="font.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                {{ font.status ? t('mobile_app_fonts.active') : t('mobile_app_fonts.inactive') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ formatDate(catalogPrimaryDate(font)) }}</span>
                                            <span v-if="catalogShowUpdatedSubtext(font)" class="d-block text-muted fs-11">
                                                {{ t('mobile_app_fonts.updated') }}: {{ formatDate(font.updated_at) }}
                                            </span>
                                            <span v-if="catalogShowDeletedSubtext(font, isDeletedView)" class="d-block text-muted fs-11">
                                                {{ t('catalog.deleted_at') }}: {{ formatDate(font.deleted_at) }}
                                            </span>
                                        </td>
                                        <td v-if="showActionsColumn" class="text-end pe-4">
                                            <div v-if="isTrashedRecord(font)" class="btn-list justify-content-end">
                                                <button
                                                    v-if="canUpdate"
                                                    type="button"
                                                    class="btn btn-sm btn-success-light btn-icon"
                                                    :title="t('catalog.restore_title')"
                                                    @click="confirmRestore(font.id)"
                                                >
                                                    <i class="ri-arrow-go-back-line"></i>
                                                </button>
                                                <button
                                                    v-if="canDelete"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('catalog.force_delete_title')"
                                                    @click="confirmForceDelete(font.id)"
                                                >
                                                    <i class="ri-delete-bin-7-line"></i>
                                                </button>
                                            </div>
                                            <div v-else-if="!isTrashedRecord(font)" class="btn-list justify-content-end">
                                                <button
                                                    v-if="canUpdate"
                                                    type="button"
                                                    class="btn btn-sm btn-info-light btn-icon"
                                                    :title="t('mobile_app_fonts.edit_title')"
                                                    @click="openEdit(font)"
                                                >
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button
                                                    v-if="canDelete"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('mobile_app_fonts.confirm_delete')"
                                                    @click="confirmDelete(font.id)"
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
                                <label class="text-muted fs-13 mb-0" for="mobile_app_fonts-per-page">{{ t('mobile_app_fonts.per_page') }}</label>
                                <select
                                    id="mobile_app_fonts-per-page"
                                    v-model.number="perPage"
                                    class="form-select form-select-sm w-auto"
                                >
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="Mobile app fonts pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('mobile_app_fonts.previous') }}
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
                                            {{ t('mobile_app_fonts.next') }}
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
import { useMobileAppFonts } from '../../../../../../composables/useMobileAppFonts';
import { useMobileAppFontsStore } from '../../../../../../stores/mobileAppFonts';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const fontsStore = useMobileAppFontsStore();

const {
    canCreate,
    canUpdate,
    canDelete,
    canChangeStatus,
    canMultipleDelete,
    showActionsColumn,
} = useCatalogPermissions('mobile_app_fonts');

const tableColumnCount = computed(() => {
    let count = 5;

    if (canMultipleDelete.value) {
        count += 1;
    }

    if (showActionsColumn.value) {
        count += 1;
    }

    return count;
});

const fontsApi = useMobileAppFonts();
const {
    mobileAppFonts,
    loading,
    pagination,
    selectedIds,
    currentPage,
    perPage,
    search,
    statusFilter,
    isDeletedView,
} = storeToRefs(fontsApi);
const {
    fetchMobileAppFonts,
    setStatusFilter,
    deleteMobileAppFont,
    deleteSelected,
    restoreMobileAppFont,
    forceDeleteMobileAppFont,
    forceDeleteSelected,
    toggleStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = fontsApi;

const counts = computed(() => ({
    total: fontsStore.total ?? pagination.value?.total ?? 0,
    active: fontsStore.activeCount ?? 0,
    inactive: fontsStore.inactiveCount ?? 0,
    deleted: fontsStore.deletedCount ?? 0,
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
    deleteItem: deleteMobileAppFont,
    restoreItem: restoreMobileAppFont,
    forceDeleteItem: forceDeleteMobileAppFont,
    forceDeleteSelected,
    i18nPrefix: 'mobile_app_fonts',
});

const selectedCount = computed(() => selectedIds.value.length);

const showAllFilter = computed(() => statusFilter.value !== 'all');

const paginationArrowIcon = computed(() => (
    locale.value === 'ar'
        ? 'ri-arrow-left-s-line fw-semibold'
        : 'ri-arrow-right-s-line fw-semibold'
));

const allSelected = computed(() => {
    if (! mobileAppFonts.value.length) {
        return false;
    }

    return mobileAppFonts.value.every((font) => selectedIds.value.includes(Number(font.id)));
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

    return t('mobile_app_fonts.showing_entries', {
        from: pagination.value.from ?? 0,
        to: pagination.value.to ?? 0,
        total: pagination.value.total ?? 0,
    });
});

function displayName(font) {
    return font.name || '-';
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

function openEdit(font) {
    modalType.value = 'edit';
    selectedRecord.value = { ...font };
    modalShow.value = true;
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchMobileAppFonts(page);
}

function onSaved() {
    modalShow.value = false;
    fetchMobileAppFonts(currentPage.value);
}

onMounted(() => {
    fetchMobileAppFonts();
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
