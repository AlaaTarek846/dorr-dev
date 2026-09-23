<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('employees.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('employees.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                        <div class="d-flex flex-wrap align-items-center gap-1 employees-toolbar-filters">
                            <div class="input-group input-group-sm employees-toolbar-search">
                                <span class="input-group-text bg-white">
                                    <i class="ri-search-line text-muted"></i>
                                </span>
                                <input
                                    v-model="search"
                                    type="search"
                                    class="form-control"
                                    :placeholder="t('employees.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border employees-search-clear"
                                    :title="t('employees.clear_search')"
                                    @click="clearSearch"
                                >
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button
                                v-if="showAllFilter"
                                type="button"
                                class="btn btn-sm employees-filter-btn"
                                :class="statusFilter === 'all' ? 'employees-filter-btn--all' : 'employees-filter-btn--all-idle'"
                                @click="setStatusFilter('all')"
                            >
                                {{ t('employees.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm employees-filter-btn"
                                :class="statusFilter === 'active' ? 'employees-filter-btn--active' : 'employees-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('employees.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm employees-filter-btn"
                                :class="statusFilter === 'inactive' ? 'employees-filter-btn--inactive' : 'employees-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('employees.filter_inactive') }} ({{ counts.inactive }})
                            </button>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <button
                                v-if="canMultipleDelete && selectedCount"
                                type="button"
                                class="btn btn-danger btn-sm btn-wave"
                                @click="confirmDeleteSelected"
                            >
                                <i class="ri-delete-bin-line me-1 align-middle"></i>
                                {{ t('employees.delete_count', { count: selectedCount }) }}
                            </button>

                            <button
                                v-if="canCreate"
                                type="button"
                                class="btn btn-primary btn-sm btn-wave"
                                @click="openCreate"
                            >
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ t('employees.add_short') }}
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
                                                :disabled="loading || !employees.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('employees.name') }}</th>
                                        <th scope="col">{{ t('email') }}</th>
                                        <th scope="col">{{ t('employees.phone') }}</th>
                                        <th scope="col">{{ t('employees.gender') }}</th>
                                        <th scope="col">{{ t('employees.status') }}</th>
                                        <th scope="col">{{ t('employees.created_at') }}</th>
                                        <th v-if="showActionsColumn" scope="col" class="text-end pe-4">{{ t('employees.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="tableColumnCount" />

                                    <tr v-else-if="!employees.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-user-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('employees.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('employees.empty') }}</p>
                                                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('employees.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr
                                            v-for="employee in employees"
                                            :key="employee.id"
                                            class="crm-contact"
                                        >
                                            <td v-if="canMultipleDelete" class="ps-4">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    :checked="isSelected(employee.id)"
                                                    @change="onRowSelect(employee.id, $event.target.checked)"
                                                >
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="avatar avatar-sm avatar-rounded">
                                                        <img
                                                            v-if="employee.avatar_thumb || employee.avatar"
                                                            :src="employee.avatar_thumb || employee.avatar"
                                                            :alt="employee.name"
                                                            class="employee-avatar-img"
                                                        >
                                                        <span v-else class="avatar avatar-sm avatar-rounded bg-primary-transparent">
                                                            <i class="ri-user-line text-primary"></i>
                                                        </span>
                                                    </span>
                                                    <div>
                                                        <button
                                                            v-if="canUpdate"
                                                            type="button"
                                                            class="btn btn-link p-0 text-start fw-semibold text-default"
                                                            @click="openEdit(employee)"
                                                        >
                                                            {{ employee.name }}
                                                        </button>
                                                        <span v-else class="fw-semibold text-default">{{ employee.name }}</span>
                                                        <span class="d-block text-muted fs-11">
                                                            #{{ employee.id }}
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>{{ employee.email }}</td>
                                            <td>
                                                <span v-if="employee.phone" class="employees-phone" dir="ltr">
                                                    {{ formatEmployeePhone(employee) }}
                                                </span>
                                                <span v-else>-</span>
                                            </td>
                                            <td>{{ genderLabel(employee.gender) }}</td>
                                            <td>
                                                <select
                                                    v-if="canChangeStatus"
                                                    class="form-select form-select-sm w-auto employees-status-select"
                                                    :class="statusSelectClass(employee.status)"
                                                    :value="String(employee.status)"
                                                    :disabled="isTogglingStatus(employee.id)"
                                                    @change="changeEmployeeStatus(employee, $event.target.value)"
                                                >
                                                    <option
                                                        v-for="(label, value) in statusOptions"
                                                        :key="value"
                                                        :value="value"
                                                    >
                                                        {{ label }}
                                                    </option>
                                                </select>
                                                <span
                                                    v-else
                                                    class="badge"
                                                    :class="employee.status ? 'bg-success-transparent' : 'bg-secondary-transparent'"
                                                >
                                                    {{ statusOptions[String(employee.status)] }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatDate(employee.created_at) }}</span>
                                                <span v-if="employee.updated_at" class="d-block text-muted fs-11">
                                                    {{ t('employees.updated') }}: {{ formatDate(employee.updated_at) }}
                                                </span>
                                            </td>
                                            <td v-if="showActionsColumn" class="text-end pe-4">
                                                <div class="btn-list justify-content-end">
                                                    <button
                                                        v-if="canUpdate"
                                                        type="button"
                                                        class="btn btn-sm btn-info-light btn-icon"
                                                        :title="t('employees.edit_title')"
                                                        @click="openEdit(employee)"
                                                    >
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button
                                                        v-if="canDelete"
                                                        type="button"
                                                        class="btn btn-sm btn-danger-light btn-icon"
                                                        :title="t('employees.confirm_delete')"
                                                        @click="confirmDelete(employee.id)"
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
                                <label class="text-muted fs-13 mb-0" for="employees-per-page">{{ t('employees.per_page') }}</label>
                                <select
                                    id="employees-per-page"
                                    v-model.number="perPage"
                                    class="form-select form-select-sm w-auto"
                                >
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="employees pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('employees.previous') }}
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
                                            {{ t('employees.next') }}
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
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useEmployees } from '../../../../../../composables/useEmployees';
import { useEmployeesStore } from '../../../../../../stores/employees';
import { formatPhoneForDisplay } from '../../../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const employeesStore = useEmployeesStore();

const {
    canCreate,
    canUpdate,
    canDelete,
    canChangeStatus,
    canMultipleDelete,
    showActionsColumn,
} = useCatalogPermissions('admins');

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

const employeesApi = useEmployees();
const {
    employees,
    loading,
    pagination,
    selectedIds,
    currentPage,
    perPage,
    search,
    statusFilter,
} = storeToRefs(employeesApi);
const {
    fetchEmployees,
    setStatusFilter,
    deleteEmployee,
    deleteSelected,
    changeEmployeeStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = employeesApi;

const counts = computed(() => ({
    total: employeesStore.total ?? pagination.value?.total ?? 0,
    active: employeesStore.activeCount ?? 0,
    inactive: employeesStore.inactiveCount ?? 0,
}));

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const deleteConfirm = useConfirmDelete();

const selectedCount = computed(() => selectedIds.value.length);

const showAllFilter = computed(() => statusFilter.value !== 'all');

const statusOptions = computed(() => ({
    true: t('employees.filter_active'),
    false: t('employees.filter_inactive'),
}));

const paginationArrowIcon = computed(() => (
    locale.value === 'ar'
        ? 'ri-arrow-left-s-line fw-semibold'
        : 'ri-arrow-right-s-line fw-semibold'
));

const allSelected = computed(() => {
    if (! employees.value.length) {
        return false;
    }

    return employees.value.every((employee) => selectedIds.value.includes(Number(employee.id)));
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

    return t('employees.showing_entries', {
        from: pagination.value.from ?? 0,
        to: pagination.value.to ?? 0,
        total: pagination.value.total ?? 0,
    });
});

function statusSelectClass(status) {
    if (status === true) {
        return 'employees-status-select--active';
    }

    return 'employees-status-select--inactive';
}

function genderLabel(gender) {
    if (gender === 'male') {
        return t('profile.gender_male');
    }

    if (gender === 'female') {
        return t('profile.gender_female');
    }

    return '-';
}

function formatEmployeePhone(user) {
    return formatPhoneForDisplay(user?.phone, user?.country?.dial_code);
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

function openEdit(employee) {
    modalType.value = 'edit';
    selectedRecord.value = { ...employee };
    modalShow.value = true;
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchEmployees(page);
}

function confirmDelete(id) {
    deleteConfirm.open({
        title: t('employees.delete_title'),
        message: t('employees.confirm_delete'),
        payload: { type: 'single', id },
    });
}

function confirmDeleteSelected() {
    deleteConfirm.open({
        title: t('employees.delete_selected_title'),
        message: t('employees.confirm_delete_selected'),
        payload: { type: 'multiple' },
    });
}

async function handleDeleteConfirm() {
    deleteConfirm.setLoading(true);

    try {
        if (deleteConfirm.state.payload?.type === 'multiple') {
            await deleteSelected();
        } else {
            await deleteEmployee(deleteConfirm.state.payload.id);
        }
    } finally {
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
    }
}

function onSaved() {
    modalShow.value = false;
    fetchEmployees(currentPage.value);
}

onMounted(() => {
    fetchEmployees();
});
</script>

<style scoped>
.employees-toolbar-filters {
    flex-wrap: wrap;
}

.employees-toolbar-search {
    width: 210px;
    max-width: 210px;
    flex-shrink: 0;
}

.employee-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.employees-phone {
    display: inline-block;
    direction: ltr;
    unicode-bidi: isolate;
    white-space: nowrap;
}

.employees-search-clear {
    padding-inline: 0.5rem;
    line-height: 1;
}

.employees-filter-btn {
    border-width: 1px;
    border-style: solid;
    font-weight: 500;
    white-space: nowrap;
}

.employees-filter-btn--all {
    background-color: #845adf;
    border-color: #845adf;
    color: #fff;
}

.employees-filter-btn--all-idle {
    background-color: rgba(132, 90, 223, 0.12);
    border-color: rgba(132, 90, 223, 0.35);
    color: #845adf;
}

.employees-filter-btn--active {
    background-color: #26bf94;
    border-color: #26bf94;
    color: #fff;
}

.employees-filter-btn--active-idle {
    background-color: rgba(38, 191, 148, 0.12);
    border-color: rgba(38, 191, 148, 0.35);
    color: #26bf94;
}

.employees-filter-btn--inactive {
    background-color: #6c757d;
    border-color: #6c757d;
    color: #fff;
}

.employees-filter-btn--inactive-idle {
    background-color: #f3f6f8;
    border-color: #dee2e6;
    color: #6c757d;
}

.employees-status-select {
    min-width: 7.5rem;
    font-weight: 500;
    border-width: 1px;
}

.employees-status-select--active {
    background-color: rgba(38, 191, 148, 0.12);
    border-color: rgba(38, 191, 148, 0.35);
    color: #26bf94;
}

.employees-status-select--inactive {
    background-color: #f3f6f8;
    border-color: #dee2e6;
    color: #6c757d;
}

@media (max-width: 767.98px) {
    .employees-toolbar-search {
        width: 100%;
        max-width: 220px;
    }
}
</style>
