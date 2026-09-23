<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('roles.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('roles.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="input-group input-group-sm" style="max-width: 280px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('roles.search')">
                </div>
                <div class="d-flex gap-2">
                    <button
                        v-if="canMultipleDelete && selectedCount"
                        type="button"
                        class="btn btn-danger btn-sm"
                        @click="confirmDeleteSelected"
                    >
                        {{ t('roles.delete_count', { count: selectedCount }) }}
                    </button>
                    <button v-if="canCreate" type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <i class="ri-add-line me-1"></i>{{ t('roles.add_short') }}
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th v-if="canMultipleDelete" class="ps-4" style="width:48px">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        :checked="allSelected"
                                        :disabled="loading || !roles.length"
                                        @change="toggleSelectAll($event.target.checked)"
                                    >
                                </th>
                                <th>{{ t('roles.name') }}</th>
                                <th>{{ t('roles.permissions_count') }}</th>
                                <th>{{ t('roles.users_count') }}</th>
                                <th>{{ t('roles.created_at') }}</th>
                                <th v-if="showActionsColumn" class="text-end pe-4">{{ t('roles.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :columns="tableColumnCount" />
                            <tr v-else-if="!roles.length">
                                <td :colspan="tableColumnCount" class="text-center py-5 text-muted">{{ t('roles.empty') }}</td>
                            </tr>
                            <template v-else>
                            <tr v-for="role in roles" :key="role.id">
                                <td v-if="canMultipleDelete" class="ps-4">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        :checked="selectedIds.includes(Number(role.id))"
                                        @change="toggleSelect(role.id, $event.target.checked)"
                                    >
                                </td>
                                <td>
                                    <button
                                        v-if="canUpdate"
                                        type="button"
                                        class="btn btn-link p-0 fw-semibold"
                                        @click="openEdit(role)"
                                    >
                                        {{ role.name }}
                                    </button>
                                    <span v-else class="fw-semibold">{{ role.name }}</span>
                                    <span v-if="role.name === 'super-admin'" class="badge bg-warning-transparent ms-1">{{ t('roles.system') }}</span>
                                </td>
                                <td>{{ role.permissions_count ?? 0 }}</td>
                                <td>{{ role.users_count ?? 0 }}</td>
                                <td>
                                    <span class="d-block">{{ formatDate(catalogPrimaryDate(role)) }}</span>
                                    <span v-if="catalogShowUpdatedSubtext(role)" class="d-block text-muted fs-11">
                                        {{ t('roles.updated') }}: {{ formatDate(role.updated_at) }}
                                    </span>
                                </td>
                                <td v-if="showActionsColumn" class="text-end pe-4">
                                    <button
                                        v-if="canUpdate"
                                        type="button"
                                        class="btn btn-sm btn-info-light btn-icon"
                                        @click="openEdit(role)"
                                    >
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button
                                        v-if="canDelete"
                                        type="button"
                                        class="btn btn-sm btn-danger-light btn-icon"
                                        :disabled="role.name === 'super-admin'"
                                        @click="confirmDelete(role.id)"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="pagination && !loading" class="card-footer">
                <div class="d-flex align-items-center gap-2 justify-content-end">
                    <select v-model.number="perPage" class="form-select form-select-sm w-auto">
                        <option :value="15">15</option>
                        <option :value="25">25</option>
                    </select>
                    <button
                        type="button"
                        class="btn btn-sm btn-light"
                        :disabled="!pagination.prev_page_url"
                        @click="fetchRoles(currentPage - 1)"
                    >
                        {{ t('roles.previous') }}
                    </button>
                    <span class="text-muted fs-13">{{ currentPage }} / {{ pagination.last_page }}</span>
                    <button
                        type="button"
                        class="btn btn-sm btn-light"
                        :disabled="!pagination.next_page_url"
                        @click="fetchRoles(currentPage + 1)"
                    >
                        {{ t('roles.next') }}
                    </button>
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
import ConfirmDeleteModal from '../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useRoles } from '../../../../../../composables/useRoles';
import {
    catalogPrimaryDate,
    catalogShowUpdatedSubtext,
    formatCatalogDate,
} from '../../../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();

const {
    canCreate,
    canUpdate,
    canDelete,
    canMultipleDelete,
    showActionsColumn,
} = useCatalogPermissions('roles');

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

const rolesApi = useRoles();
const {
    roles, loading, pagination, selectedIds, currentPage, perPage, search,
} = storeToRefs(rolesApi);
const {
    fetchRoles, deleteRole, deleteSelected, toggleSelectAll, toggleSelect,
} = rolesApi;

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const deleteConfirm = useConfirmDelete();

const selectedCount = computed(() => selectedIds.value.length);
const allSelected = computed(() => roles.value.length > 0 && roles.value.every((r) => selectedIds.value.includes(Number(r.id))));

watch(perPage, () => fetchRoles(1));

function formatDate(value) {
    return formatCatalogDate(value, locale.value);
}

function openCreate() {
    modalType.value = 'create';
    selectedRecord.value = null;
    modalShow.value = true;
}

function openEdit(role) {
    modalType.value = 'edit';
    selectedRecord.value = { ...role };
    modalShow.value = true;
}

function confirmDelete(id) {
    deleteConfirm.open({ title: t('roles.delete_title'), message: t('roles.confirm_delete'), payload: { type: 'single', id } });
}

function confirmDeleteSelected() {
    deleteConfirm.open({ title: t('roles.delete_selected_title'), message: t('roles.confirm_delete_selected'), payload: { type: 'multiple' } });
}

async function handleDeleteConfirm() {
    deleteConfirm.setLoading(true);
    try {
        if (deleteConfirm.state.payload?.type === 'multiple') {
            await deleteSelected();
        } else {
            await deleteRole(deleteConfirm.state.payload.id);
        }
    } finally {
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
    }
}

function onSaved() {
    modalShow.value = false;
    fetchRoles(currentPage.value);
}

onMounted(() => fetchRoles());
</script>
