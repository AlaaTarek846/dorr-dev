<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('service_categories.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('service_categories.title') }}</li>
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
                                    :placeholder="t('service_categories.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('service_categories.clear_search')"
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
                                {{ t('service_categories.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('service_categories.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('service_categories.filter_inactive') }} ({{ counts.inactive }})
                            </button>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <button
                                v-if="selectedCount"
                                type="button"
                                class="btn btn-danger btn-sm btn-wave"
                                @click="confirmDeleteSelected"
                            >
                                <i class="ri-delete-bin-line me-1 align-middle"></i>
                                {{ t('service_categories.delete_count', { count: selectedCount }) }}
                            </button>

                            <button type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ t('service_categories.add_short') }}
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
                                                :disabled="loading || !categories.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('service_categories.name') }}</th>
                                        <th scope="col">{{ t('service_categories.parent') }}</th>
                                        <th scope="col">{{ t('service_categories.module_name') }}</th>
                                        <th scope="col">{{ t('service_categories.is_login_dashboard') }}</th>
                                        <th scope="col">{{ t('service_categories.is_auto_assign') }}</th>
                                        <th scope="col">{{ t('service_categories.requires_provider') }}</th>
                                        <th scope="col">{{ t('service_categories.sort_order') }}</th>
                                        <th scope="col">{{ t('service_categories.status') }}</th>
                                        <th scope="col">{{ t('service_categories.created_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('service_categories.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" />

                                    <tr v-else-if="!categories.length">
                                        <td colspan="11" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-list-settings-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('service_categories.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('service_categories.empty') }}</p>
                                                <button type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('service_categories.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                    <tr
                                        v-for="category in categories"
                                        :key="category.id"
                                    >
                                        <td class="ps-4">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="isSelected(category.id)"
                                                @change="onRowSelect(category.id, $event.target.checked)"
                                            >
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="service-category-image-wrap">
                                                    <img
                                                        v-if="category.image_thumb || category.image"
                                                        :src="category.image_thumb || category.image"
                                                        :alt="displayName(category)"
                                                        class="service-category-image"
                                                    >
                                                    <img
                                                        v-else
                                                        src="/dashboard/assets/images/faces/9.jpg"
                                                        :alt="displayName(category)"
                                                        class="service-category-image"
                                                    >
                                                </span>
                                                <div>
                                                    <button
                                                        type="button"
                                                        class="btn btn-link p-0 text-start fw-semibold text-default"
                                                        @click="openEdit(category)"
                                                    >
                                                        {{ displayName(category) }}
                                                    </button>
                                                    <span class="d-block text-muted fs-11">
                                                        #{{ category.id }}
                                                        <span v-if="category.is_leaf" class="badge bg-info-transparent ms-1">
                                                            {{ t('service_categories.leaf') }}
                                                        </span>
                                                        <span v-else class="badge bg-warning-transparent ms-1">
                                                            {{ t('service_categories.has_children') }}
                                                        </span>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span v-if="category.parent" class="badge bg-primary-transparent">
                                                {{ displayName(category.parent) }}
                                            </span>
                                            <span v-else class="text-muted">{{ t('service_categories.no_parent') }}</span>
                                        </td>
                                        <td>
                                            <span v-if="category.module_name" class="badge bg-light text-default">
                                                {{ category.module_name }}
                                            </span>
                                            <span v-else class="text-muted">—</span>
                                        </td>
                                        <td>
                                            <span
                                                class="badge"
                                                :class="category.is_login_dashboard ? 'bg-success-transparent' : 'bg-secondary-transparent'"
                                            >
                                                {{ category.is_login_dashboard ? t('yes') : t('no') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span
                                                class="badge"
                                                :class="category.is_auto_assign ? 'bg-success-transparent' : 'bg-secondary-transparent'"
                                            >
                                                {{ category.is_auto_assign ? t('yes') : t('no') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span
                                                class="badge"
                                                :class="category.requires_provider ? 'bg-success-transparent' : 'bg-secondary-transparent'"
                                            >
                                                {{ category.requires_provider ? t('yes') : t('no') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-default">{{ category.sort_order ?? 0 }}</span>
                                        </td>
                                        <td>
                                            <div
                                                class="toggle toggle-success mb-0 catalog-status-toggle"
                                                :class="{
                                                    on: category.status,
                                                    'catalog-status-toggle--loading': isTogglingStatus(category.id),
                                                }"
                                                role="button"
                                                tabindex="0"
                                                :aria-busy="isTogglingStatus(category.id)"
                                                @click="toggleStatus(category)"
                                                @keydown.enter.space.prevent="toggleStatus(category)"
                                            >
                                                <span></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ formatDate(category.created_at) }}</span>
                                            <span v-if="category.updated_at" class="d-block text-muted fs-11">
                                                {{ t('service_categories.updated') }}: {{ formatDate(category.updated_at) }}
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-list justify-content-end">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-info-light btn-icon"
                                                    :title="t('service_categories.edit_title')"
                                                    @click="openEdit(category)"
                                                >
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('service_categories.confirm_delete')"
                                                    @click="confirmDelete(category.id)"
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
                                <label class="text-muted fs-13 mb-0" for="service-categories-per-page">{{ t('service_categories.per_page') }}</label>
                                <select
                                    id="service-categories-per-page"
                                    v-model.number="perPage"
                                    class="form-select form-select-sm w-auto"
                                >
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="Service categories pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('service_categories.previous') }}
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
                                            {{ t('service_categories.next') }}
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
import { computed, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import ConfirmDeleteModal from '../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../components/ui/TableSkeleton.vue';
import { useConfirmDelete } from '../../../../composables/useConfirmDelete';
import { useServiceCategories } from '../../../../composables/useServiceCategories';
import { useServiceCategoriesStore } from '../../../../stores/serviceCategories';
import { displayTranslatedName, formatCatalogDate } from '../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const categoriesStore = useServiceCategoriesStore();

const categoriesApi = useServiceCategories();
const {
    categories,
    loading,
    pagination,
    selectedIds,
    currentPage,
    perPage,
    search,
    statusFilter,
} = storeToRefs(categoriesApi);
const {
    fetchCategories,
    fetchCounts,
    setStatusFilter,
    deleteCategory,
    deleteSelected,
    toggleStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = categoriesApi;

const counts = computed(() => ({
    total: categoriesStore.total ?? pagination.value?.total ?? 0,
    active: categoriesStore.activeCount ?? 0,
    inactive: categoriesStore.inactiveCount ?? 0,
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
    if (! categories.value.length) {
        return false;
    }

    return categories.value.every((category) => selectedIds.value.includes(Number(category.id)));
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

function displayName(category) {
    return displayTranslatedName(category, locale.value);
}

function formatDate(value) {
    return formatCatalogDate(value, locale.value);
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

    return t('service_categories.showing_entries', {
        from: pagination.value.from ?? 0,
        to: pagination.value.to ?? 0,
        total: pagination.value.total ?? 0,
    });
});

function clearSearch() {
    search.value = '';
}

function openCreate() {
    modalType.value = 'create';
    selectedRecord.value = null;
    modalShow.value = true;
}

function openEdit(category) {
    modalType.value = 'edit';
    selectedRecord.value = { ...category };
    modalShow.value = true;
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchCategories(page);
}

function confirmDelete(id) {
    deleteConfirm.open({
        title: t('service_categories.delete_title'),
        message: t('service_categories.confirm_delete'),
        payload: { type: 'single', id },
    });
}

function confirmDeleteSelected() {
    deleteConfirm.open({
        title: t('service_categories.delete_selected_title'),
        message: t('service_categories.confirm_delete_selected'),
        payload: { type: 'multiple' },
    });
}

async function handleDeleteConfirm() {
    deleteConfirm.setLoading(true);

    try {
        if (deleteConfirm.state.payload?.type === 'multiple') {
            await deleteSelected();
        } else {
            await deleteCategory(deleteConfirm.state.payload.id);
        }
    } finally {
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
    }
}

function onSaved() {
    modalShow.value = false;
    fetchCategories(currentPage.value);
}

onMounted(() => {
    fetchCategories();
    fetchCounts();
});

watch(statusFilter, () => {
    fetchCounts();
});
</script>

<style scoped>
.catalog-toolbar-filters {
    flex-wrap: wrap;
}

.catalog-toolbar-search {
    width: 210px;
    max-width: 210px;
    flex-shrink: 0;
}

.catalog-search-clear {
    padding-inline: 0.5rem;
    line-height: 1;
}

.catalog-filter-btn {
    border-width: 1px;
    border-style: solid;
    font-weight: 500;
    white-space: nowrap;
}

.catalog-filter-btn--all {
    background-color: #845adf;
    border-color: #845adf;
    color: #fff;
}

.catalog-filter-btn--all-idle {
    background-color: rgba(132, 90, 223, 0.12);
    border-color: rgba(132, 90, 223, 0.35);
    color: #845adf;
}

.catalog-filter-btn--active {
    background-color: #26bf94;
    border-color: #26bf94;
    color: #fff;
}

.catalog-filter-btn--active-idle {
    background-color: rgba(38, 191, 148, 0.12);
    border-color: rgba(38, 191, 148, 0.35);
    color: #26bf94;
}

.catalog-filter-btn--inactive {
    background-color: #6c757d;
    border-color: #6c757d;
    color: #fff;
}

.catalog-filter-btn--inactive-idle {
    background-color: #f3f6f8;
    border-color: #dee2e6;
    color: #6c757d;
}

.service-category-image-wrap {
    flex-shrink: 0;
}

.service-category-image {
    display: block;
    width: 32px;
    height: 32px;
    object-fit: cover;
    border-radius: 0.375rem;
}

.service-category-image--placeholder {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.12);
    color: rgb(var(--primary-rgb, 132, 90, 223));
    font-size: 1rem;
}

@media (max-width: 767.98px) {
    .catalog-toolbar-search {
        width: 100%;
        max-width: 220px;
    }
}
</style>
