<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('sms.providers.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('sms.providers.title') }}</li>
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
                                    :placeholder="t('sms.providers.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('sms.providers.clear_search')"
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
                                {{ t('sms.providers.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('sms.providers.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('sms.providers.filter_inactive') }} ({{ counts.inactive }})
                            </button>
                        </div>

                        <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                            <button
                                v-if="canMultipleDelete && selectedCount"
                                type="button"
                                class="btn btn-danger btn-sm btn-wave"
                                @click="confirmDeleteSelected"
                            >
                                <i class="ri-delete-bin-line me-1 align-middle"></i>
                                {{ t('sms.providers.delete_count', { count: selectedCount }) }}
                            </button>
                            <button
                                v-if="canCreate"
                                type="button"
                                class="btn btn-primary btn-sm btn-wave"
                                @click="openCreate"
                            >
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ t('sms.providers.add_short') }}
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
                                                :disabled="loading || !providers.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('sms.providers.name') }}</th>
                                        <th scope="col">{{ t('sms.providers.available') }}</th>
                                        <th scope="col">{{ t('sms.providers.priority') }}</th>
                                        <th scope="col">{{ t('sms.providers.test_status') }}</th>
                                        <th scope="col">{{ t('sms.providers.status') }}</th>
                                        <th scope="col">{{ t('sms.providers.created_at') }}</th>
                                        <th v-if="showActionsColumn" scope="col" class="text-end pe-4">{{ t('sms.providers.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="tableColumnCount" />

                                    <tr v-else-if="!providers.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-chat-sms-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('sms.providers.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('sms.providers.empty') }}</p>
                                                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('sms.providers.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                    <tr
                                        v-for="provider in providers"
                                        :key="provider.id"
                                        class="crm-contact"
                                    >
                                        <td v-if="canMultipleDelete" class="ps-4">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="isSelected(provider.id)"
                                                @change="onRowSelect(provider.id, $event.target.checked)"
                                            >
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div>
                                                    <button
                                                        v-if="canUpdate"
                                                        type="button"
                                                        class="btn btn-link p-0 text-start fw-semibold text-default"
                                                        @click="openEdit(provider)"
                                                    >
                                                        {{ provider.name }}
                                                    </button>
                                                    <span v-else class="fw-semibold text-default">{{ provider.name }}</span>
                                                    <span class="d-block text-muted fs-11">
                                                        {{ provider.label }} · #{{ provider.id }}
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge" :class="provider.is_available ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                {{ provider.is_available ? t('sms.providers.yes') : t('sms.providers.no') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-transparent">{{ provider.priority ?? 1 }}</span>
                                        </td>
                                        <td>
                                            <TestStatusBadge :test-status="provider.test_status" :test-error="provider.test_error" />
                                        </td>
                                        <td>
                                            <div
                                                v-if="canChangeStatus"
                                                class="toggle toggle-success mb-0 catalog-status-toggle"
                                                :class="{
                                                    on: provider.is_active,
                                                    'catalog-status-toggle--loading': isTogglingStatus(provider.id),
                                                }"
                                                role="button"
                                                tabindex="0"
                                                :aria-busy="isTogglingStatus(provider.id)"
                                                @click="toggleActive(provider)"
                                                @keydown.enter.space.prevent="toggleActive(provider)"
                                            >
                                                <span></span>
                                            </div>
                                            <span v-else class="badge" :class="provider.is_active ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                {{ provider.is_active ? t('sms.providers.filter_active') : t('sms.providers.filter_inactive') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ formatCatalogDate(provider.created_at, locale) }}</span>
                                            <span v-if="provider.updated_at" class="d-block text-muted fs-11">
                                                {{ t('sms.providers.updated') }}: {{ formatCatalogDate(provider.updated_at, locale) }}
                                            </span>
                                        </td>
                                        <td v-if="showActionsColumn" class="text-end pe-4">
                                            <div class="btn-list justify-content-end">
                                                <button
                                                    v-if="canTest"
                                                    type="button"
                                                    class="btn btn-sm btn-success-light btn-icon"
                                                    :title="t('sms.providers.test_title')"
                                                    @click="testProvider(provider)"
                                                >
                                                    <i class="ri-stethoscope-line"></i>
                                                </button>
                                                <button
                                                    v-if="canUpdate"
                                                    type="button"
                                                    class="btn btn-sm btn-info-light btn-icon"
                                                    :title="t('sms.providers.edit_title')"
                                                    @click="openEdit(provider)"
                                                >
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button
                                                    v-if="canDelete"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('sms.providers.confirm_delete')"
                                                    @click="confirmDelete(provider.id)"
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
                                <label class="text-muted fs-13 mb-0" for="sms-providers-per-page">{{ t('sms.providers.per_page') }}</label>
                                <Select
                                    filter
                                    :filter-placeholder="t('search_placeholder')"
                                    id="sms-providers-per-page"
                                    v-model="perPage"
                                    :options="perPageOptions"
                                    option-label="label"
                                    option-value="value"
                                    append-to="self"
                                    size="small"
                                    class="w-auto"
                                />
                            </div>

                            <nav aria-label="SMS providers pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('sms.providers.previous') }}
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
                                            {{ t('sms.providers.next') }}
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
import Select from 'primevue/select';
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import ConfirmDeleteModal from '../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import TestStatusBadge from '../../../../../../components/ui/TestStatusBadge.vue';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useSmsConfirmDelete } from '../../../../../../composables/useSmsConfirmDelete';
import { usePermission } from '../../../../../../composables/usePermission';
import { useSmsProvidersStore } from '../../../../../../stores/smsProviders';
import { useSmsProviders } from '../../../../../../composables/useSmsProviders';
import { formatCatalogDate } from '../../../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const providersStore = useSmsProvidersStore();
const { can } = usePermission();

const {
    canCreate,
    canUpdate,
    canDelete,
    canChangeStatus,
    canMultipleDelete,
    showActionsColumn,
} = useCatalogPermissions('sms-providers');

const canTest = computed(() => can('sms-providers.test'));

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

const providersApi = useSmsProviders();
const {
    providers,
    loading,
    pagination,
    selectedIds,
    currentPage,
    perPage,
    search,
    statusFilter,
} = storeToRefs(providersApi);
const {
    fetchProviders,
    setStatusFilter,
    deleteProvider,
    deleteSelected,
    toggleActive,
    testProvider,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = providersApi;

const counts = computed(() => ({
    total: providersStore.total ?? pagination.value?.total ?? 0,
    active: providersStore.activeCount ?? 0,
    inactive: providersStore.inactiveCount ?? 0,
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

const perPageOptions = [
    { label: '15', value: 15 },
    { label: '25', value: 25 },
    { label: '50', value: 50 },
];

const allSelected = computed(() => {
    if (! providers.value.length) {
        return false;
    }

    return providers.value.every((provider) => selectedIds.value.includes(Number(provider.id)));
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

    return t('sms.providers.showing_entries', {
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

function openEdit(provider) {
    modalType.value = 'edit';
    selectedRecord.value = { ...provider };
    modalShow.value = true;
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchProviders(page);
}

const {
    confirmDelete,
    confirmDeleteSelected,
    handleDeleteConfirm,
} = useSmsConfirmDelete({
    t,
    deleteConfirm,
    deleteSelected,
    deleteItem: deleteProvider,
    i18nPrefix: 'sms.providers',
});

function onSaved() {
    modalShow.value = false;
    fetchProviders(currentPage.value);
}

onMounted(() => {
    fetchProviders();
});
</script>