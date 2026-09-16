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
                                {{ t('service_categories.filter_all') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('service_categories.filter_active') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('service_categories.filter_inactive') }}
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
                                        <th scope="col">{{ t('service_categories.name_ar') }}</th>
                                        <th scope="col">{{ t('service_categories.department') }}</th>
                                        <th scope="col">{{ t('service_categories.base_model') }}</th>
                                        <th scope="col">{{ t('service_categories.requires_provider') }}</th>
                                        <th scope="col">{{ t('service_categories.parent') }}</th>
                                        <th scope="col">{{ t('service_categories.status') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('service_categories.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" />

                                    <tr v-else-if="!categories.length">
                                        <td colspan="8" class="border-0">
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
                                            <button
                                                type="button"
                                                class="btn btn-link p-0 text-start fw-semibold text-default"
                                                @click="openEdit(category)"
                                            >
                                                {{ displayName(category) }}
                                            </button>
                                            <span class="d-block text-muted fs-11">
                                                #{{ category.id }} · {{ category.slug }}
                                                <span v-if="category.is_leaf" class="badge bg-info-transparent ms-1">
                                                    {{ t('service_categories.leaf') }}
                                                </span>
                                                <span v-else class="badge bg-warning-transparent ms-1">
                                                    {{ t('service_categories.has_children') }}
                                                </span>
                                            </span>
                                        </td>
                                        <td>
                                            <span v-if="category.department" class="badge bg-secondary-transparent">
                                                {{ category.department }}
                                            </span>
                                            <span v-else class="text-muted">-</span>
                                        </td>
                                        <td>
                                            <code v-if="category.base_model">{{ category.base_model }}</code>
                                            <span v-else class="text-muted">-</span>
                                        </td>
                                        <td>
                                            <span
                                                class="badge"
                                                :class="category.requires_provider ? 'bg-success-transparent' : 'bg-secondary-transparent'"
                                            >
                                                {{ category.requires_provider ? t('yes') : t('no') }}
                                            </span>
                                            <span v-if="category.requires_provider && category.provider_type_label" class="d-block fs-11 text-muted">
                                                {{ category.provider_type_label }}
                                            </span>
                                        </td>
                                        <td>
                                            <span v-if="category.parent" class="badge bg-primary-transparent">
                                                {{ displayName(category.parent) }}
                                            </span>
                                            <span v-else class="text-muted">{{ t('service_categories.no_parent') }}</span>
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
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDeleteModal from '../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../components/ui/TableSkeleton.vue';
import { useConfirmDelete } from '../../../../composables/useConfirmDelete';
import { useServiceCategories } from '../../../../composables/useServiceCategories';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();

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
} = categoriesApi;
const {
    fetchCategories,
    setStatusFilter,
    deleteCategory,
    deleteSelected,
    toggleStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = categoriesApi;

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const deleteConfirm = useConfirmDelete();

const selectedCount = computed(() => selectedIds.value.length);
const showAllFilter = computed(() => statusFilter.value !== 'all');

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
    if (! category) {
        return '-';
    }

    return locale.value === 'ar'
        ? (category.name_ar || category.name_en || '-')
        : (category.name_en || category.name_ar || '-');
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
        title: t('service_categories.title'),
        message: t('service_categories.confirm_delete'),
        payload: { type: 'single', id },
    });
}

function confirmDeleteSelected() {
    deleteConfirm.open({
        title: t('service_categories.title'),
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
});
</script>
