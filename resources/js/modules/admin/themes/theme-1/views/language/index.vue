<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('languages.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('languages.title') }}</li>
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
                                    :placeholder="t('languages.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('languages.clear_search')"
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
                                {{ t('languages.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('languages.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('languages.filter_inactive') }} ({{ counts.inactive }})
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
                                v-if="selectedCount && statusFilter !== 'deleted'"
                                type="button"
                                class="btn btn-danger btn-sm btn-wave"
                                @click="confirmDeleteSelected"
                            >
                                <i class="ri-delete-bin-line me-1 align-middle"></i>
                                {{ t('catalog.bulk_delete_count', { count: selectedCount }) }}
                            </button>
                            <button
                                v-if="selectedCount && statusFilter === 'deleted'"
                                type="button"
                                class="btn btn-danger btn-sm btn-wave"
                                @click="confirmForceDeleteSelected"
                            >
                                <i class="ri-delete-bin-7-line me-1 align-middle"></i>
                                {{ t('catalog.force_delete_count', { count: selectedCount }) }}
                            </button>
                            <button
                                v-if="statusFilter !== 'deleted'"
                                type="button"
                                class="btn btn-primary btn-sm btn-wave"
                                @click="openCreate"
                            >
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ t('languages.add_short') }}
                            </button>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col" class="ps-4" style="width: 48px;">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="allSelected"
                                                :disabled="loading || !languages.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('languages.name') }}</th>
                                        <th scope="col">{{ t('languages.code') }}</th>
                                        <th scope="col">{{ t('languages.direction') }}</th>
                                        <th scope="col">{{ t('languages.is_default_website') }}</th>
                                        <th scope="col">{{ t('languages.is_default_dashboard') }}</th>
                                        <th scope="col">{{ t('languages.stores_translation') }}</th>
                                        <th scope="col">{{ t('languages.status') }}</th>
                                        <th scope="col">{{ t('languages.created_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('languages.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="10" />

                                    <tr v-else-if="!languages.length">
                                        <td colspan="10" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-translate-2 fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('languages.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('languages.empty') }}</p>
                                                <button v-if="!isDeletedView" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('languages.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr
                                            v-for="language in languages"
                                            :key="language.id"
                                            class="crm-contact"
                                        >
                                            <td class="ps-4">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    :checked="isSelected(language.id)"
                                                    @change="onRowSelect(language.id, $event.target.checked)"
                                                >
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <FlagImage
                                                        :code="resolveRecordFlagCode(language)"
                                                        :width="32"
                                                        :height="24"
                                                        :size="32"
                                                    />
                                                    <div>
                                                        <button
                                                            v-if="!isTrashedRecord(language)"
                                                            type="button"
                                                            class="btn btn-link p-0 text-start fw-semibold text-default"
                                                            @click="openEdit(language)"
                                                        >
                                                            {{ displayTranslatedName(language, locale) }}
                                                        </button>
                                                        <span v-else class="fw-semibold text-default">{{ displayTranslatedName(language, locale) }}</span>
                                                        <span class="d-block text-muted fs-11">
                                                            #{{ language.id }}
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary-transparent">{{ language.code }}</span>
                                            </td>
                                            <td>
                                                <span
                                                    class="badge"
                                                    :class="directionBadgeClass(language.direction)"
                                                >
                                                    {{ directionLabel(language.direction) }}
                                                </span>
                                            </td>
                                            <td>
                                                <span
                                                    class="badge catalog-bool-badge"
                                                    :class="language.is_default_website
                                                        ? 'bg-success-transparent text-success'
                                                        : 'bg-light text-muted'"
                                                >
                                                    <i
                                                        :class="language.is_default_website
                                                            ? 'ri-check-line'
                                                            : 'ri-close-line'"
                                                    ></i>
                                                    {{ language.is_default_website
                                                        ? t('languages.yes')
                                                        : t('languages.no') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span
                                                    class="badge catalog-bool-badge"
                                                    :class="language.is_default_dashboard
                                                        ? 'bg-success-transparent text-success'
                                                        : 'bg-light text-muted'"
                                                >
                                                    <i
                                                        :class="language.is_default_dashboard
                                                            ? 'ri-check-line'
                                                            : 'ri-close-line'"
                                                    ></i>
                                                    {{ language.is_default_dashboard
                                                        ? t('languages.yes')
                                                        : t('languages.no') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span
                                                    class="badge catalog-bool-badge"
                                                    :class="language.stores_translation
                                                        ? 'bg-success-transparent text-success'
                                                        : 'bg-light text-muted'"
                                                >
                                                    <i
                                                        :class="language.stores_translation
                                                            ? 'ri-check-line'
                                                            : 'ri-close-line'"
                                                    ></i>
                                                    {{ language.stores_translation
                                                        ? t('languages.yes')
                                                        : t('languages.no') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span v-if="isTrashedRecord(language)" class="badge bg-danger-transparent">
                                                    {{ t('catalog.deleted_badge') }}
                                                </span>
                                                <div
                                                    v-else
                                                    class="toggle toggle-success mb-0 catalog-status-toggle"
                                                    :class="{
                                                        on: language.status,
                                                        'catalog-status-toggle--loading': isTogglingStatus(language.id),
                                                    }"
                                                    role="button"
                                                    tabindex="0"
                                                    :aria-busy="isTogglingStatus(language.id)"
                                                    @click="toggleStatus(language)"
                                                    @keydown.enter.space.prevent="toggleStatus(language)"
                                                >
                                                    <span></span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatCatalogDate(catalogPrimaryDate(language), locale) }}</span>
                                                <span v-if="catalogShowUpdatedSubtext(language)" class="d-block text-muted fs-11">
                                                    {{ t('languages.updated') }}: {{ formatCatalogDate(language.updated_at, locale) }}
                                                </span>
                                                <span v-if="catalogShowDeletedSubtext(language, isDeletedView)" class="d-block text-muted fs-11">
                                                    {{ t('catalog.deleted_at') }}: {{ formatCatalogDate(language.deleted_at, locale) }}
                                                </span>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div v-if="isTrashedRecord(language)" class="btn-list justify-content-end">
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-success-light btn-icon"
                                                        :title="t('catalog.restore_title')"
                                                        @click="confirmRestore(language.id)"
                                                    >
                                                        <i class="ri-arrow-go-back-line"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-danger-light btn-icon"
                                                        :title="t('catalog.force_delete_title')"
                                                        @click="confirmForceDelete(language.id)"
                                                    >
                                                        <i class="ri-delete-bin-7-line"></i>
                                                    </button>
                                                </div>
                                                <div v-else-if="!isTrashedRecord(language)" class="btn-list justify-content-end">
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-info-light btn-icon"
                                                        :title="t('languages.edit_title')"
                                                        @click="openEdit(language)"
                                                    >
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-danger-light btn-icon"
                                                        :title="t('languages.confirm_delete')"
                                                        @click="confirmDelete(language.id)"
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
                                <label class="text-muted fs-13 mb-0" for="languages-per-page">{{ t('languages.per_page') }}</label>
                                <select
                                    id="languages-per-page"
                                    v-model.number="perPage"
                                    class="form-select form-select-sm w-auto"
                                >
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="Languages pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('languages.previous') }}
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
                                            {{ t('languages.next') }}
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
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useLanguages } from '../../../../../../composables/useLanguages';
import { useAvailableLanguagesStore } from '../../../../../../stores/availableLanguages';
import { useLanguagesStore } from '../../../../../../stores/languages';
import {
    displayTranslatedName,
    formatCatalogDate,
    catalogPrimaryDate,
    catalogShowDeletedSubtext,
    catalogShowUpdatedSubtext,
    isTrashedRecord,
    resolveRecordFlagCode,
} from '../../../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const languagesStore = useLanguagesStore();
const availableLanguagesStore = useAvailableLanguagesStore();

const languagesApi = useLanguages();
const {
    languages,
    loading,
    pagination,
    selectedIds,
    currentPage,
    perPage,
    search,
    statusFilter,
    isDeletedView,
} = storeToRefs(languagesApi);
const {
    fetchLanguages,
    setStatusFilter,
    deleteLanguage,
    deleteSelected,
    restoreLanguage,
    forceDeleteLanguage,
    forceDeleteSelected,
    toggleStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = languagesApi;

const counts = computed(() => ({
    total: languagesStore.total ?? pagination.value?.total ?? 0,
    active: languagesStore.activeCount ?? 0,
    inactive: languagesStore.inactiveCount ?? 0,
    deleted: languagesStore.deletedCount ?? 0,
}));

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const deleteConfirm = useConfirmDelete();

const selectedCount = computed(() => selectedIds.value.length);

const showAllFilter = computed(() => statusFilter.value !== 'all');

const paginationArrowIcon = computed(() => (
    locale.value === 'ar'
        ? 'ri-arrow-left-s-line fw-semibold'
        : 'ri-arrow-right-s-line fw-semibold'
));

const allSelected = computed(() => {
    if (! languages.value.length) {
        return false;
    }

    return languages.value.every((language) => selectedIds.value.includes(Number(language.id)));
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

    return t('languages.showing_entries', {
        from: pagination.value.from ?? 0,
        to: pagination.value.to ?? 0,
        total: pagination.value.total ?? 0,
    });
});

function directionLabel(direction) {
    if (direction === 'rtl') {
        return t('languages.direction_rtl');
    }

    if (direction === 'ltr') {
        return t('languages.direction_ltr');
    }

    return direction || '-';
}

function directionBadgeClass(direction) {
    return direction === 'rtl'
        ? 'bg-warning-transparent text-warning'
        : 'bg-info-transparent text-info';
}

function clearSearch() {
    search.value = '';
}

function openCreate() {
    modalType.value = 'create';
    selectedRecord.value = null;
    modalShow.value = true;
}

function openEdit(language) {
    modalType.value = 'edit';
    selectedRecord.value = { ...language };
    modalShow.value = true;
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchLanguages(page);
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
    deleteItem: deleteLanguage,
    restoreItem: restoreLanguage,
    forceDeleteItem: forceDeleteLanguage,
    forceDeleteSelected,
    i18nPrefix: 'languages',
});

async function onSaved() {
    modalShow.value = false;
    await Promise.all([
        fetchLanguages(currentPage.value),
        availableLanguagesStore.fetch(true),
    ]);
}

onMounted(() => {
    fetchLanguages();
});
</script>
