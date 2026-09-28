<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('sms.accounts.title') }}
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
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.sms.providers.index' }">{{ t('sms.providers.title') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('sms.accounts.title') }}</li>
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
                                    :placeholder="t('sms.accounts.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('sms.accounts.clear_search')"
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
                                {{ t('sms.accounts.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('sms.accounts.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('sms.accounts.filter_inactive') }} ({{ counts.inactive }})
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
                                {{ t('sms.accounts.delete_count', { count: selectedCount }) }}
                            </button>
                            <button
                                v-if="canCreate"
                                type="button"
                                class="btn btn-primary btn-sm btn-wave"
                                @click="openCreate"
                            >
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ t('sms.accounts.add_short') }}
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
                                                :disabled="loading || !accounts.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('sms.accounts.name') }}</th>
                                        <th scope="col">{{ t('sms.accounts.provider') }}</th>
                                        <th scope="col">{{ t('sms.accounts.sender') }}</th>
                                        <th scope="col">{{ t('sms.accounts.default') }}</th>
                                        <th scope="col">{{ t('sms.accounts.test_status') }}</th>
                                        <th scope="col">{{ t('sms.accounts.status') }}</th>
                                        <th scope="col">{{ t('sms.accounts.created_at') }}</th>
                                        <th v-if="showActionsColumn" scope="col" class="text-end pe-4">{{ t('sms.accounts.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="tableColumnCount" />

                                    <tr v-else-if="!accounts.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-chat-sms-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('sms.accounts.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('sms.accounts.empty') }}</p>
                                                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('sms.accounts.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                    <tr
                                        v-for="account in accounts"
                                        :key="account.id"
                                        class="crm-contact"
                                    >
                                        <td v-if="canMultipleDelete" class="ps-4">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="isSelected(account.id)"
                                                @change="onRowSelect(account.id, $event.target.checked)"
                                            >
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div>
                                                    <button
                                                        v-if="canUpdate"
                                                        type="button"
                                                        class="btn btn-link p-0 text-start fw-semibold text-default"
                                                        @click="openEdit(account)"
                                                    >
                                                        {{ account.name }}
                                                    </button>
                                                    <span v-else class="fw-semibold text-default">{{ account.name }}</span>
                                                    <span class="d-block text-muted fs-11">
                                                        #{{ account.id }}
                                                        <i
                                                            v-if="account.is_usable"
                                                            class="ri-checkbox-circle-fill text-success ms-1"
                                                            :title="t('sms.accounts.usable')"
                                                        ></i>
                                                        <i
                                                            v-else
                                                            class="ri-error-warning-fill text-danger ms-1"
                                                            :title="t('sms.accounts.not_usable')"
                                                        ></i>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-transparent">{{ account.provider_label || account.provider_key || '-' }}</span>
                                        </td>
                                        <td>
                                            <template v-if="account.sender">
                                                <span class="d-block">{{ account.sender }}</span>
                                                <span v-if="account.sender_type" class="badge bg-info-transparent fs-10">
                                                    {{ t(`sms.accounts.sender_type_${account.sender_type}`) }}
                                                </span>
                                            </template>
                                            <span v-else class="text-muted">-</span>
                                        </td>
                                        <td>
                                            <span v-if="account.is_default" class="badge bg-warning-transparent">
                                                {{ t('sms.accounts.default') }}
                                            </span>
                                            <span v-else class="text-muted">-</span>
                                        </td>
                                        <td>
                                            <TestStatusBadge :test-status="account.test_status" :test-error="account.test_error" />
                                        </td>
                                        <td>
                                            <div
                                                v-if="canChangeStatus"
                                                class="toggle toggle-success mb-0 catalog-status-toggle"
                                                :class="{
                                                    on: account.is_active,
                                                    'catalog-status-toggle--loading': isTogglingStatus(account.id),
                                                }"
                                                role="button"
                                                tabindex="0"
                                                :aria-busy="isTogglingStatus(account.id)"
                                                @click="toggleActive(account)"
                                                @keydown.enter.space.prevent="toggleActive(account)"
                                            >
                                                <span></span>
                                            </div>
                                            <span v-else class="badge" :class="account.is_active ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                {{ account.is_active ? t('sms.accounts.filter_active') : t('sms.accounts.filter_inactive') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ formatCatalogDate(account.created_at, locale) }}</span>
                                            <span v-if="account.updated_at" class="d-block text-muted fs-11">
                                                {{ t('sms.accounts.updated') }}: {{ formatCatalogDate(account.updated_at, locale) }}
                                            </span>
                                        </td>
                                        <td v-if="showActionsColumn" class="text-end pe-4">
                                            <div class="btn-list justify-content-end">
                                                <button
                                                    v-if="canTest"
                                                    type="button"
                                                    class="btn btn-sm btn-success-light btn-icon"
                                                    :title="t('sms.accounts.test_title')"
                                                    @click="testConnection(account)"
                                                >
                                                    <i class="ri-stethoscope-line"></i>
                                                </button>
                                                <button
                                                    v-if="canUpdate && !account.is_default"
                                                    type="button"
                                                    class="btn btn-sm btn-warning-light btn-icon"
                                                    :title="t('sms.accounts.set_default')"
                                                    @click="setDefault(account)"
                                                >
                                                    <i class="ri-star-line"></i>
                                                </button>
                                                <button
                                                    v-if="canTest && account.supports_balance"
                                                    type="button"
                                                    class="btn btn-sm btn-primary-light btn-icon"
                                                    :title="t('sms.accounts.balance')"
                                                    @click="fetchBalance(account)"
                                                >
                                                    <i class="ri-wallet-3-line"></i>
                                                </button>
                                                <button
                                                    v-if="canUpdate"
                                                    type="button"
                                                    class="btn btn-sm btn-info-light btn-icon"
                                                    :title="t('sms.accounts.edit_title')"
                                                    @click="openEdit(account)"
                                                >
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button
                                                    v-if="canDelete"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('sms.accounts.confirm_delete')"
                                                    @click="confirmDelete(account.id)"
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
                                <label class="text-muted fs-13 mb-0" for="sms-accounts-per-page">{{ t('sms.accounts.per_page') }}</label>
                                <Select
                                    id="sms-accounts-per-page"
                                    v-model="perPage"
                                    :options="perPageOptions"
                                    option-label="label"
                                    option-value="value"
                                    append-to="self"
                                    size="small"
                                    class="w-auto"
                                />
                            </div>

                            <nav aria-label="SMS accounts pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('sms.accounts.previous') }}
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
                                            {{ t('sms.accounts.next') }}
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
import { useSmsAccountsStore } from '../../../../../../stores/smsAccounts';
import { useSmsAccounts } from '../../../../../../composables/useSmsAccounts';
import { formatCatalogDate } from '../../../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const accountsStore = useSmsAccountsStore();
const { can } = usePermission();

const {
    canCreate,
    canUpdate,
    canDelete,
    canChangeStatus,
    canMultipleDelete,
    showActionsColumn,
} = useCatalogPermissions('sms-accounts');

const canTest = computed(() => can('sms-accounts.test'));

const tableColumnCount = computed(() => {
    let count = 8;

    if (canMultipleDelete.value) {
        count += 1;
    }

    if (showActionsColumn.value) {
        count += 1;
    }

    return count;
});

const accountsApi = useSmsAccounts();
const {
    accounts,
    loading,
    pagination,
    selectedIds,
    currentPage,
    perPage,
    search,
    statusFilter,
} = storeToRefs(accountsApi);
const {
    fetchAccounts,
    setStatusFilter,
    deleteAccount,
    deleteSelected,
    toggleActive,
    setDefault,
    testConnection,
    fetchBalance,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = accountsApi;

const counts = computed(() => ({
    total: accountsStore.total ?? pagination.value?.total ?? 0,
    active: accountsStore.activeCount ?? 0,
    inactive: accountsStore.inactiveCount ?? 0,
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
    if (! accounts.value.length) {
        return false;
    }

    return accounts.value.every((account) => selectedIds.value.includes(Number(account.id)));
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

    return t('sms.accounts.showing_entries', {
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

function openEdit(account) {
    modalType.value = 'edit';
    selectedRecord.value = { ...account };
    modalShow.value = true;
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchAccounts(page);
}

const {
    confirmDelete,
    confirmDeleteSelected,
    handleDeleteConfirm,
} = useSmsConfirmDelete({
    t,
    deleteConfirm,
    deleteSelected,
    deleteItem: deleteAccount,
    i18nPrefix: 'sms.accounts',
});

function onSaved() {
    modalShow.value = false;
    fetchAccounts(currentPage.value);
}

onMounted(() => {
    fetchAccounts();
});
</script>