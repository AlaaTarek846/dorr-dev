<template>
    <div>
        <WalletPageHeader :title="t('wallet.methods.title')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="filters.search" type="search" class="form-control" :placeholder="t('wallet.methods.search')">
                        <button
                            v-if="filters.search"
                            type="button"
                            class="btn btn-light border"
                            :title="t('wallet.common.clear_search')"
                            @click="filters.search = ''"
                        >
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                </div>
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <button
                        v-if="canMultipleDelete && selectedCount"
                        type="button"
                        class="btn btn-danger btn-sm btn-wave"
                        @click="confirmDeleteSelected"
                    >
                        <i class="ri-delete-bin-line me-1 align-middle"></i>
                        {{ t('catalog.bulk_delete_count', { count: selectedCount }) }}
                    </button>
                    <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                        <i class="ri-add-line me-1 align-middle"></i>{{ t('wallet.methods.add') }}
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
                                        :disabled="loading || !rows.length"
                                        @change="onSelectAll($event.target.checked)"
                                    >
                                </th>
                                <th :class="{ 'ps-4': !canMultipleDelete }">{{ t('wallet.methods.name') }}</th>
                                <th>{{ t('wallet.methods.gateway') }}</th>
                                <th>{{ t('wallet.methods.availability') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('wallet.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :columns="tableColumnCount" />
                            <tr v-else-if="!rows.length">
                                <td :colspan="tableColumnCount" class="border-0">
                                    <div class="text-center py-5">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                            <i class="ri-secure-payment-line fs-2 text-primary"></i>
                                        </span>
                                        <p class="fw-semibold mb-1">{{ t('wallet.common.empty_title') }}</p>
                                        <p class="text-muted mb-0">{{ t('wallet.common.empty') }}</p>
                                    </div>
                                </td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" class="crm-contact">
                                    <td v-if="canMultipleDelete" class="ps-4">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            :checked="isSelected(row.id)"
                                            @change="onRowSelect(row.id, $event.target.checked)"
                                        >
                                    </td>
                                    <td :class="{ 'ps-4': !canMultipleDelete }">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent">
                                                <i class="ri-secure-payment-line text-primary"></i>
                                            </span>
                                            <div>
                                                <button v-if="canUpdate" type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click="openEdit(row)">
                                                    {{ row.name || row.code }}
                                                </button>
                                                <span v-else class="fw-semibold text-default">{{ row.name || row.code }}</span>
                                                <span class="d-block text-muted fs-11">#{{ row.id }} · {{ row.code }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary-transparent">{{ row.gateway }}</span>
                                        <span class="badge bg-secondary-transparent ms-1">{{ row.type }}</span>
                                    </td>
                                    <td>
                                        <span v-if="row.is_global" class="badge bg-info-transparent">{{ t('wallet.methods.global') }}</span>
                                        <template v-else>
                                            <span v-for="c in row.countries" :key="c.country_id" class="badge me-1" :class="c.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ c.country_code }}</span>
                                            <span v-if="!row.countries?.length" class="text-muted fs-12">{{ t('wallet.methods.no_countries') }}</span>
                                        </template>
                                    </td>
                                    <td>
                                        <div
                                            v-if="canChangeStatus"
                                            class="toggle toggle-success mb-0 catalog-status-toggle"
                                            :class="{ on: row.status }"
                                            role="button"
                                            tabindex="0"
                                            @click="toggleStatus(row)"
                                            @keydown.enter.space.prevent="toggleStatus(row)"
                                        ><span></span></div>
                                        <span v-else class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ row.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('wallet.methods.edit')" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                            <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('wallet.common.delete')" @click="confirmDelete(row.id)"><i class="ri-delete-bin-line"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
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
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import ConfirmDeleteModal from '../../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import { useConfirmDelete } from '../../../../../../../composables/useConfirmDelete';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const canCreate = computed(() => can('payment-methods.create'));
const canUpdate = computed(() => can('payment-methods.update'));
const canDelete = computed(() => can('payment-methods.delete'));
const canChangeStatus = computed(() => can('payment-methods.change-status'));
const canMultipleDelete = computed(() => can('payment-methods.multiple-delete'));

const { rows, loading, pagination, filters, fetch } = useWalletList('payment-methods', { defaults: { search: '' } });
fetch(1);

const tableColumnCount = computed(() => 5 + (canMultipleDelete.value ? 1 : 0));

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const deleteConfirm = useConfirmDelete();

// Row selection for the bulk delete (cleared whenever the page of rows changes).
const selectedIds = ref([]);
const selectedCount = computed(() => selectedIds.value.length);
const allSelected = computed(() => rows.value.length > 0 && rows.value.every((row) => selectedIds.value.includes(Number(row.id))));

watch(rows, () => {
    selectedIds.value = [];
});

function isSelected(id) {
    return selectedIds.value.includes(Number(id));
}

function onRowSelect(id, checked) {
    const value = Number(id);

    selectedIds.value = checked
        ? [...new Set([...selectedIds.value, value])]
        : selectedIds.value.filter((item) => item !== value);
}

function onSelectAll(checked) {
    selectedIds.value = checked ? rows.value.map((row) => Number(row.id)) : [];
}

function openCreate() {
    modalType.value = 'create';
    selectedRecord.value = null;
    modalShow.value = true;
}

function openEdit(row) {
    modalType.value = 'edit';
    selectedRecord.value = { ...row };
    modalShow.value = true;
}

function onSaved() {
    modalShow.value = false;
    fetch();
}

async function toggleStatus(row) {
    try {
        await adminAxios.patch(`/api/admin/v1/payment-methods/${row.id}/status`, { status: ! row.status });
        row.status = ! row.status;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

function confirmDelete(id) {
    deleteConfirm.open({
        title: t('wallet.methods.delete_title'),
        message: t('wallet.methods.confirm_delete'),
        payload: { type: 'delete-single', id },
    });
}

function confirmDeleteSelected() {
    deleteConfirm.open({
        title: t('wallet.methods.delete_selected_title'),
        message: t('wallet.methods.confirm_delete_selected'),
        payload: { type: 'delete-multiple' },
    });
}

async function handleDeleteConfirm() {
    deleteConfirm.setLoading(true);

    try {
        const payload = deleteConfirm.state.payload;

        if (payload?.type === 'delete-multiple') {
            await adminAxios.post('/api/admin/v1/payment-methods/delete-multiple', { ids: selectedIds.value });
        } else {
            await adminAxios.delete(`/api/admin/v1/payment-methods/${payload.id}`);
        }

        showSuccess(t('wallet.methods.deleted'));
        selectedIds.value = [];
        fetch();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
    }
}
</script>
