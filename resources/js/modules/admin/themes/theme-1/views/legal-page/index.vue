<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('legal_pages.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('legal_pages.title') }}</li>
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
                                    :placeholder="t('legal_pages.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('legal_pages.clear_search')"
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
                                {{ t('legal_pages.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('legal_pages.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('legal_pages.filter_inactive') }} ({{ counts.inactive }})
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

                            <span class="toolbar-separator"></span>

                            <button
                                v-for="option in typeOptions"
                                :key="option.value"
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="typeFilter === option.value ? 'catalog-filter-btn--all' : 'catalog-filter-btn--all-idle'"
                                @click="onTypeFilter(option.value)"
                            >
                                {{ option.label }}
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
                                {{ t('legal_pages.add_short') }}
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
                                                :disabled="loading || !pages.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('legal_pages.type') }}</th>
                                        <th scope="col">{{ t('legal_pages.service') }}</th>
                                        <th scope="col">{{ t('legal_pages.content') }}</th>
                                        <th scope="col">{{ t('legal_pages.status') }}</th>
                                        <th scope="col">{{ t('legal_pages.created_at') }}</th>
                                        <th v-if="showActionsColumn" scope="col" class="text-end pe-4">{{ t('legal_pages.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="tableColumnCount" />

                                    <tr v-else-if="!pages.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-file-shield-2-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('legal_pages.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('legal_pages.empty') }}</p>
                                                <button v-if="canCreate && !isDeletedView" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('legal_pages.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                    <tr
                                        v-for="page in pages"
                                        :key="page.id"
                                        class="crm-contact"
                                    >
                                        <td v-if="canMultipleDelete" class="ps-4">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="isSelected(page.id)"
                                                @change="onRowSelect(page.id, $event.target.checked)"
                                            >
                                        </td>
                                        <td>
                                            <span class="badge bg-info-transparent">
                                                {{ typeLabel(page.type) }}
                                            </span>
                                            <span class="d-block text-muted fs-11">
                                                #{{ page.id }}
                                            </span>
                                        </td>
                                        <td>
                                            <span v-if="page.service?.name" class="badge bg-primary-transparent">
                                                {{ page.service.name }}
                                            </span>
                                            <span v-else class="badge bg-secondary-transparent">
                                                {{ t('legal_pages.general') }}
                                            </span>
                                        </td>
                                        <td>
                                            <button
                                                v-if="canUpdate && !isTrashedRecord(page)"
                                                type="button"
                                                class="btn btn-link p-0 text-start text-default text-wrap faq-content-cell"
                                                @click="openEdit(page)"
                                            >
                                                {{ displayContent(page) }}
                                            </button>
                                            <span v-else class="text-wrap faq-content-cell">{{ displayContent(page) }}</span>
                                        </td>
                                        <td>
                                            <span v-if="isTrashedRecord(page)" class="badge bg-danger-transparent">
                                                {{ t('catalog.deleted_badge') }}
                                            </span>
                                            <div
                                                v-else-if="canChangeStatus"
                                                class="toggle toggle-success mb-0 catalog-status-toggle"
                                                :class="{
                                                    on: page.status,
                                                    'catalog-status-toggle--loading': isTogglingStatus(page.id),
                                                }"
                                                role="button"
                                                tabindex="0"
                                                :aria-busy="isTogglingStatus(page.id)"
                                                @click="toggleStatus(page)"
                                                @keydown.enter.space.prevent="toggleStatus(page)"
                                            >
                                                <span></span>
                                            </div>
                                            <span v-else class="badge" :class="page.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                {{ page.status ? t('legal_pages.active') : t('legal_pages.inactive') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ formatDate(page.created_at) }}</span>
                                            <span v-if="catalogShowUpdatedSubtext(page)" class="d-block text-muted fs-11">
                                                {{ t('legal_pages.updated') }}: {{ formatDate(page.updated_at) }}
                                            </span>
                                            <span v-if="catalogShowDeletedSubtext(page, isDeletedView)" class="d-block text-muted fs-11">
                                                {{ t('catalog.deleted_at') }}: {{ formatDate(page.deleted_at) }}
                                            </span>
                                        </td>
                                        <td v-if="showActionsColumn" class="text-end pe-4">
                                            <div v-if="isTrashedRecord(page)" class="btn-list justify-content-end">
                                                <button
                                                    v-if="canUpdate"
                                                    type="button"
                                                    class="btn btn-sm btn-success-light btn-icon"
                                                    :title="t('catalog.restore_title')"
                                                    @click="confirmRestore(page.id)"
                                                >
                                                    <i class="ri-arrow-go-back-line"></i>
                                                </button>
                                                <button
                                                    v-if="canDelete"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('catalog.force_delete_title')"
                                                    @click="confirmForceDelete(page.id)"
                                                >
                                                    <i class="ri-delete-bin-7-line"></i>
                                                </button>
                                            </div>
                                            <div v-else-if="!isTrashedRecord(page)" class="btn-list justify-content-end">
                                                <button
                                                    v-if="canUpdate"
                                                    type="button"
                                                    class="btn btn-sm btn-info-light btn-icon"
                                                    :title="t('legal_pages.edit_title')"
                                                    @click="openEdit(page)"
                                                >
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button
                                                    v-if="canDelete"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('legal_pages.confirm_delete')"
                                                    @click="confirmDelete(page.id)"
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
                                <label class="text-muted fs-13 mb-0" for="pages-per-page">{{ t('legal_pages.per_page') }}</label>
                                <select
                                    id="pages-per-page"
                                    v-model.number="perPage"
                                    class="form-select form-select-sm w-auto"
                                >
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="Legal pages pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('legal_pages.previous') }}
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
                                            {{ t('legal_pages.next') }}
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
            :default-type="typeFilter"
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
    catalogShowDeletedSubtext,
    catalogShowUpdatedSubtext,
    isTrashedRecord,
    richTextToPlainText,
} from '../../../../../../utils/catalog';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useLegalPages } from '../../../../../../composables/useLegalPages';
import { useLegalPagesStore } from '../../../../../../stores/legalPages';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const pagesStore = useLegalPagesStore();

const {
    canCreate,
    canUpdate,
    canDelete,
    canChangeStatus,
    canMultipleDelete,
    showActionsColumn,
} = useCatalogPermissions('legal-page');

const tableColumnCount = computed(() => {
    let count = 6;

    if (canMultipleDelete.value) {
        count += 1;
    }

    if (showActionsColumn.value) {
        count += 1;
    }

    return count;
});

const pagesApi = useLegalPages();
const {
    pages,
    loading,
    pagination,
    selectedIds,
    currentPage,
    perPage,
    search,
    statusFilter,
    typeFilter,
    isDeletedView,
} = storeToRefs(pagesApi);
const {
    fetchPages,
    setStatusFilter,
    setTypeFilter,
    deletePage,
    deleteSelected,
    restorePage,
    forceDeletePage,
    forceDeleteSelected,
    toggleStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = pagesApi;

const counts = computed(() => ({
    total: pagesStore.total ?? pagination.value?.total ?? 0,
    active: pagesStore.activeCount ?? 0,
    inactive: pagesStore.inactiveCount ?? 0,
    deleted: pagesStore.deletedCount ?? 0,
}));

const typeOptions = computed(() => [
    { value: 'privacy', label: t('legal_pages.type_privacy') },
    { value: 'term', label: t('legal_pages.type_term') },
]);

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
    deleteItem: deletePage,
    restoreItem: restorePage,
    forceDeleteItem: forceDeletePage,
    forceDeleteSelected,
    i18nPrefix: 'legal_pages',
});

const selectedCount = computed(() => selectedIds.value.length);

const showAllFilter = computed(() => statusFilter.value !== 'all');

const paginationArrowIcon = computed(() => (
    locale.value === 'ar'
        ? 'ri-arrow-left-s-line fw-semibold'
        : 'ri-arrow-right-s-line fw-semibold'
));

const allSelected = computed(() => {
    if (! pages.value.length) {
        return false;
    }

    return pages.value.every((page) => selectedIds.value.includes(Number(page.id)));
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

function onTypeFilter(value) {
    setTypeFilter(typeFilter.value === value ? '' : value);
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

    return t('legal_pages.showing_entries', {
        from: pagination.value.from ?? 0,
        to: pagination.value.to ?? 0,
        total: pagination.value.total ?? 0,
    });
});

function typeLabel(value) {
    return value === 'term' ? t('legal_pages.type_term') : t('legal_pages.type_privacy');
}

function displayContent(page) {
    const translation = page.translations?.find((item) => item.locale === locale.value);
    const content = translation?.content || page.content;

    return richTextToPlainText(content, { maxLength: 120 });
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

function openEdit(page) {
    modalType.value = 'edit';
    selectedRecord.value = { ...page };
    modalShow.value = true;
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchPages(page);
}

function onSaved() {
    modalShow.value = false;
    fetchPages(currentPage.value);
}

onMounted(() => {
    fetchPages();
});
</script>

<style scoped>
.faq-content-cell {
    display: inline-block;
    max-width: 26rem;
    white-space: normal;
    word-break: break-word;
}

.toolbar-separator {
    width: 1px;
    height: 22px;
    background: var(--default-border, #dee2e6);
    margin: 0 0.25rem;
}
</style>