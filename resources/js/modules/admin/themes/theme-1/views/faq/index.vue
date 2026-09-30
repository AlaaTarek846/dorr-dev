<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('faqs.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('faqs.title') }}</li>
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
                                    :placeholder="t('faqs.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('faqs.clear_search')"
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
                                {{ t('faqs.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('faqs.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('faqs.filter_inactive') }} ({{ counts.inactive }})
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
                                v-if="canUpdate && statusFilter !== 'deleted'"
                                type="button"
                                class="btn btn-sm btn-wave"
                                :class="reorderMode ? 'btn-primary' : 'btn-outline-primary'"
                                @click="toggleReorderMode"
                            >
                                <i class="ri-drag-move-2-line me-1 align-middle"></i>
                                {{ reorderMode ? t('faqs.reorder_done') : t('faqs.reorder') }}
                            </button>
                            <button
                                v-if="canCreate && statusFilter !== 'deleted' && ! reorderMode"
                                type="button"
                                class="btn btn-primary btn-sm btn-wave"
                                @click="openCreate"
                            >
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ t('faqs.add_short') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="reorderMode" class="card-body p-4">
                        <FaqReorderPanel
                            ref="reorderPanelRef"
                            :can-update="canUpdate"
                            :locale="locale"
                        />
                    </div>

                    <div v-else class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th v-if="canMultipleDelete" scope="col" class="ps-4" style="width: 48px;">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="allSelected"
                                                :disabled="loading || !faqs.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('faqs.question') }}</th>
                                        <th scope="col">{{ t('faqs.service') }}</th>
                                        <th scope="col">{{ t('faqs.status') }}</th>
                                        <th scope="col">{{ t('faqs.created_at') }}</th>
                                        <th v-if="showActionsColumn" scope="col" class="text-end pe-4">{{ t('faqs.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="tableColumnCount" />

                                    <tr v-else-if="!faqs.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-question-answer-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('faqs.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('faqs.empty') }}</p>
                                                <button v-if="canCreate && !isDeletedView" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('faqs.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                    <tr
                                        v-for="faq in faqs"
                                        :key="faq.id"
                                        class="crm-contact"
                                    >
                                        <td v-if="canMultipleDelete" class="ps-4">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="isSelected(faq.id)"
                                                @change="onRowSelect(faq.id, $event.target.checked)"
                                            >
                                        </td>
                                        <td>
                                            <button
                                                v-if="canUpdate && !isTrashedRecord(faq)"
                                                type="button"
                                                class="btn btn-link p-0 text-start fw-semibold text-default text-wrap"
                                                @click="openEdit(faq)"
                                            >
                                                {{ displayQuestion(faq) }}
                                            </button>
                                            <span v-else class="fw-semibold text-default text-wrap">{{ displayQuestion(faq) }}</span>
                                            <span class="d-block text-muted fs-11">
                                                #{{ faq.id }}
                                            </span>
                                        </td>
                                        <td>
                                            <span v-if="faq.service?.name" class="badge bg-primary-transparent">
                                                {{ faq.service.name }}
                                            </span>
                                            <span v-else class="badge bg-secondary-transparent">
                                                {{ t('faqs.general') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span v-if="isTrashedRecord(faq)" class="badge bg-danger-transparent">
                                                {{ t('catalog.deleted_badge') }}
                                            </span>
                                            <div
                                                v-else-if="canChangeStatus"
                                                class="toggle toggle-success mb-0 catalog-status-toggle"
                                                :class="{
                                                    on: faq.status,
                                                    'catalog-status-toggle--loading': isTogglingStatus(faq.id),
                                                }"
                                                role="button"
                                                tabindex="0"
                                                :aria-busy="isTogglingStatus(faq.id)"
                                                @click="toggleStatus(faq)"
                                                @keydown.enter.space.prevent="toggleStatus(faq)"
                                            >
                                                <span></span>
                                            </div>
                                            <span v-else class="badge" :class="faq.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                {{ faq.status ? t('faqs.active') : t('faqs.inactive') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ formatDate(faq.created_at) }}</span>
                                            <span v-if="catalogShowUpdatedSubtext(faq)" class="d-block text-muted fs-11">
                                                {{ t('faqs.updated') }}: {{ formatDate(faq.updated_at) }}
                                            </span>
                                            <span v-if="catalogShowDeletedSubtext(faq, isDeletedView)" class="d-block text-muted fs-11">
                                                {{ t('catalog.deleted_at') }}: {{ formatDate(faq.deleted_at) }}
                                            </span>
                                        </td>
                                        <td v-if="showActionsColumn" class="text-end pe-4">
                                            <div v-if="isTrashedRecord(faq)" class="btn-list justify-content-end">
                                                <button
                                                    v-if="canUpdate"
                                                    type="button"
                                                    class="btn btn-sm btn-success-light btn-icon"
                                                    :title="t('catalog.restore_title')"
                                                    @click="confirmRestore(faq.id)"
                                                >
                                                    <i class="ri-arrow-go-back-line"></i>
                                                </button>
                                                <button
                                                    v-if="canDelete"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('catalog.force_delete_title')"
                                                    @click="confirmForceDelete(faq.id)"
                                                >
                                                    <i class="ri-delete-bin-7-line"></i>
                                                </button>
                                            </div>
                                            <div v-else-if="!isTrashedRecord(faq)" class="btn-list justify-content-end">
                                                <button
                                                    v-if="canUpdate"
                                                    type="button"
                                                    class="btn btn-sm btn-info-light btn-icon"
                                                    :title="t('faqs.edit_title')"
                                                    @click="openEdit(faq)"
                                                >
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button
                                                    v-if="canDelete"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('faqs.confirm_delete')"
                                                    @click="confirmDelete(faq.id)"
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

                    <div v-if="! reorderMode && pagination && !loading" class="card-footer border-top-0">
                        <div class="d-flex align-items-center flex-wrap gap-3">
                            <div class="d-flex align-items-center gap-2 text-muted fs-13">
                                <span>{{ entriesLabel }}</span>
                                <i :class="paginationArrowIcon"></i>
                            </div>

                            <div class="d-flex align-items-center gap-2 ms-md-auto">
                                <label class="text-muted fs-13 mb-0" for="faqs-per-page">{{ t('faqs.per_page') }}</label>
                                <select
                                    id="faqs-per-page"
                                    v-model.number="perPage"
                                    class="form-select form-select-sm w-auto"
                                >
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="Faqs pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('faqs.previous') }}
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
                                            {{ t('faqs.next') }}
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
    catalogShowDeletedSubtext,
    catalogShowUpdatedSubtext,
    isTrashedRecord,
} from '../../../../../../utils/catalog';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useFaqs } from '../../../../../../composables/useFaqs';
import { useFaqsStore } from '../../../../../../stores/faqs';
import FaqReorderPanel from '../../../../../../components/catalog/FaqReorderPanel.vue';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const faqsStore = useFaqsStore();

const {
    canCreate,
    canUpdate,
    canDelete,
    canChangeStatus,
    canMultipleDelete,
    showActionsColumn,
} = useCatalogPermissions('faqs');

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

const faqsApi = useFaqs();
const {
    faqs,
    loading,
    pagination,
    selectedIds,
    currentPage,
    perPage,
    search,
    statusFilter,
    isDeletedView,
} = storeToRefs(faqsApi);
const {
    fetchFaqs,
    setStatusFilter,
    deleteFaq,
    deleteSelected,
    restoreFaq,
    forceDeleteFaq,
    forceDeleteSelected,
    toggleStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = faqsApi;

const counts = computed(() => ({
    total: faqsStore.total ?? pagination.value?.total ?? 0,
    active: faqsStore.activeCount ?? 0,
    inactive: faqsStore.inactiveCount ?? 0,
    deleted: faqsStore.deletedCount ?? 0,
}));

const reorderMode = ref(false);
const reorderPanelRef = ref(null);
const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const deleteConfirm = useConfirmDelete();

function toggleReorderMode() {
    reorderMode.value = ! reorderMode.value;

    if (reorderMode.value) {
        reorderPanelRef.value?.reload?.();
    } else {
        fetchFaqs(currentPage.value);
    }
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
    deleteItem: deleteFaq,
    restoreItem: restoreFaq,
    forceDeleteItem: forceDeleteFaq,
    forceDeleteSelected,
    i18nPrefix: 'faqs',
});

const selectedCount = computed(() => selectedIds.value.length);

const showAllFilter = computed(() => statusFilter.value !== 'all');

const paginationArrowIcon = computed(() => (
    locale.value === 'ar'
        ? 'ri-arrow-left-s-line fw-semibold'
        : 'ri-arrow-right-s-line fw-semibold'
));

const allSelected = computed(() => {
    if (! faqs.value.length) {
        return false;
    }

    return faqs.value.every((faq) => selectedIds.value.includes(Number(faq.id)));
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

    return t('faqs.showing_entries', {
        from: pagination.value.from ?? 0,
        to: pagination.value.to ?? 0,
        total: pagination.value.total ?? 0,
    });
});

function displayQuestion(faq) {
    const translation = faq.translations?.find((item) => item.locale === locale.value);

    return translation?.question || faq.question || '-';
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

function openEdit(faq) {
    modalType.value = 'edit';
    selectedRecord.value = { ...faq };
    modalShow.value = true;
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchFaqs(page);
}

function onSaved() {
    modalShow.value = false;
    fetchFaqs(currentPage.value);
}

onMounted(() => {
    fetchFaqs();
});
</script>
