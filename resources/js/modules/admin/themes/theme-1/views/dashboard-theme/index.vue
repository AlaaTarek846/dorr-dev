<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('dashboard_themes.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('dashboard_themes.title') }}</li>
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
                                    :placeholder="t('dashboard_themes.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('dashboard_themes.clear_search')"
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
                                {{ t('dashboard_themes.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('dashboard_themes.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('dashboard_themes.filter_inactive') }} ({{ counts.inactive }})
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
                                {{ t('dashboard_themes.add_short') }}
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
                                                :disabled="loading || !dashboardThemes.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('dashboard_themes.preview_image') }}</th>
                                        <th scope="col">{{ t('dashboard_themes.name') }}</th>
                                        <th scope="col">{{ t('dashboard_themes.slug') }}</th>
                                        <th scope="col">{{ t('dashboard_themes.path') }}</th>
                                        <th scope="col">{{ t('dashboard_themes.sort_order') }}</th>
                                        <th scope="col">{{ t('dashboard_themes.status') }}</th>
                                        <th scope="col">{{ t('dashboard_themes.created_at') }}</th>
                                        <th v-if="showActionsColumn" scope="col" class="text-end pe-4">{{ t('dashboard_themes.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="tableColumnCount" />

                                    <tr v-else-if="!dashboardThemes.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-palette-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('dashboard_themes.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('dashboard_themes.empty') }}</p>
                                                <button v-if="canCreate && !isDeletedView" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('dashboard_themes.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr
                                            v-for="theme in dashboardThemes"
                                            :key="theme.id"
                                        >
                                            <td v-if="canMultipleDelete" class="ps-4">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    :checked="isSelected(theme.id)"
                                                    @change="onRowSelect(theme.id, $event.target.checked)"
                                                >
                                            </td>
                                            <td>
                                                <span class="avatar avatar-md avatar-rounded bg-light">
                                                    <img
                                                        v-if="theme.preview_image_thumb || theme.preview_image"
                                                        :src="theme.preview_image_thumb || theme.preview_image"
                                                        alt=""
                                                        class="rounded"
                                                    >
                                                    <i v-else class="ri-image-line text-muted"></i>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span>{{ displayTranslatedName(theme, locale) }}</span>
                                                    <span v-if="theme.is_default" class="badge bg-primary-transparent">
                                                        {{ t('dashboard_themes.default_badge') }}
                                                    </span>
                                                </div>
                                            </td>
                                            <td><code>{{ theme.slug }}</code></td>
                                            <td><code>{{ theme.path }}</code></td>
                                            <td>{{ theme.sort_order ?? 0 }}</td>
                                            <td>
                                                <span v-if="isTrashedRecord(theme)" class="badge bg-danger-transparent">
                                                    {{ t('catalog.deleted_badge') }}
                                                </span>
                                                <div
                                                    v-else-if="canChangeStatus"
                                                    class="toggle toggle-success mb-0 catalog-status-toggle"
                                                    :class="{
                                                        on: theme.status,
                                                        'catalog-status-toggle--loading': isTogglingStatus(theme.id),
                                                    }"
                                                    role="button"
                                                    tabindex="0"
                                                    :aria-busy="isTogglingStatus(theme.id)"
                                                    @click="toggleStatus(theme)"
                                                    @keydown.enter.space.prevent="toggleStatus(theme)"
                                                >
                                                    <span></span>
                                                </div>
                                                <span v-else class="badge" :class="theme.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                    {{ theme.status ? t('dashboard_themes.filter_active') : t('dashboard_themes.filter_inactive') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatDate(catalogPrimaryDate(theme)) }}</span>
                                                <span v-if="catalogShowUpdatedSubtext(theme)" class="d-block text-muted fs-11">
                                                    {{ t('dashboard_themes.updated') }}: {{ formatDate(theme.updated_at) }}
                                                </span>
                                                <span v-if="catalogShowDeletedSubtext(theme, isDeletedView)" class="d-block text-muted fs-11">
                                                    {{ t('catalog.deleted_at') }}: {{ formatDate(theme.deleted_at) }}
                                                </span>
                                            </td>
                                            <td v-if="showActionsColumn" class="text-end pe-4">
                                                <div v-if="isTrashedRecord(theme)" class="btn-list justify-content-end">
                                                    <button
                                                        v-if="canUpdate"
                                                        type="button"
                                                        class="btn btn-sm btn-success-light btn-icon"
                                                        :title="t('catalog.restore_title')"
                                                        @click="confirmRestore(theme.id)"
                                                    >
                                                        <i class="ri-arrow-go-back-line"></i>
                                                    </button>
                                                    <button
                                                        v-if="canDelete"
                                                        type="button"
                                                        class="btn btn-sm btn-danger-light btn-icon"
                                                        :title="t('catalog.force_delete_title')"
                                                        @click="confirmForceDelete(theme.id)"
                                                    >
                                                        <i class="ri-delete-bin-7-line"></i>
                                                    </button>
                                                </div>
                                                <div v-else-if="!isTrashedRecord(theme)" class="btn-list justify-content-end">
                                                    <button
                                                        v-if="canUpdate"
                                                        type="button"
                                                        class="btn btn-sm btn-info-light btn-icon"
                                                        :title="t('dashboard_themes.edit_title')"
                                                        @click="openEdit(theme)"
                                                    >
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button
                                                        v-if="canDelete"
                                                        type="button"
                                                        class="btn btn-sm btn-danger-light btn-icon"
                                                        :title="t('dashboard_themes.confirm_delete')"
                                                        :disabled="theme.is_default"
                                                        @click="confirmDelete(theme.id)"
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

                        <div v-if="pagination?.total" class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <span class="text-muted fs-12">{{ entriesLabel }}</span>
                            <nav>
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('dashboard_themes.previous') }}
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
                                            {{ t('dashboard_themes.next') }}
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
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useCatalogTrashActions } from '../../../../../../composables/useCatalogTrashActions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useDashboardThemes } from '../../../../../../composables/useDashboardThemes';
import { useDashboardThemesStore } from '../../../../../../stores/dashboardThemes';
import {
    catalogPrimaryDate,
    catalogShowDeletedSubtext,
    catalogShowUpdatedSubtext,
    displayTranslatedName,
    isTrashedRecord,
} from '../../../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const themesStore = useDashboardThemesStore();

const {
    canCreate,
    canUpdate,
    canDelete,
    canChangeStatus,
    canMultipleDelete,
    showActionsColumn,
} = useCatalogPermissions('dashboard_themes');

const tableColumnCount = computed(() => {
    let count = 7;

    if (canMultipleDelete.value) {
        count += 1;
    }

    if (showActionsColumn.value) {
        count += 1;
    }

    return count;
});

const themesApi = useDashboardThemes();
const {
    dashboardThemes,
    loading,
    pagination,
    selectedIds,
    currentPage,
    search,
    statusFilter,
    isDeletedView,
} = storeToRefs(themesApi);
const {
    fetchDashboardThemes,
    setStatusFilter,
    deleteDashboardTheme,
    deleteSelected,
    restoreDashboardTheme,
    forceDeleteDashboardTheme,
    forceDeleteSelected,
    toggleStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = themesApi;

const counts = computed(() => ({
    total: themesStore.total ?? pagination.value?.total ?? 0,
    active: themesStore.activeCount ?? 0,
    inactive: themesStore.inactiveCount ?? 0,
    deleted: themesStore.deletedCount ?? 0,
}));

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const deleteConfirm = useConfirmDelete();

const selectedCount = computed(() => selectedIds.value.length);

const showAllFilter = computed(() => statusFilter.value !== 'all');

const allSelected = computed(() => {
    if (! dashboardThemes.value.length) {
        return false;
    }

    return dashboardThemes.value.every((theme) => selectedIds.value.includes(Number(theme.id)));
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

    return t('dashboard_themes.showing_entries', {
        from: pagination.value.from ?? 0,
        to: pagination.value.to ?? 0,
        total: pagination.value.total ?? 0,
    });
});

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

function openEdit(theme) {
    modalType.value = 'edit';
    selectedRecord.value = { ...theme };
    modalShow.value = true;
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchDashboardThemes(page);
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
    deleteSelected,
    deleteItem: deleteDashboardTheme,
    restoreItem: restoreDashboardTheme,
    forceDeleteItem: forceDeleteDashboardTheme,
    forceDeleteSelected,
    i18nPrefix: 'dashboard_themes',
});

function onSaved() {
    modalShow.value = false;
    fetchDashboardThemes(currentPage.value);
}

onMounted(() => {
    fetchDashboardThemes();
});
</script>
