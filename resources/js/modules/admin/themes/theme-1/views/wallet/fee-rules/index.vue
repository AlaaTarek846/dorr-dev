<template>
    <div>
        <WalletPageHeader :title="t('wallet.rules.title')" :total="pagination?.total ?? null" />

        <div class="alert alert-info fs-13">{{ t('wallet.rules.intro') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="filters.search" type="search" class="form-control" :placeholder="t('wallet.rules.search')">
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
                    <WalletStatusTabs :model-value="statusFilter" :counts="counts" @update:model-value="setStatusFilter" />
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
                        <i class="ri-add-line me-1 align-middle"></i>{{ t('wallet.rules.add') }}
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
                                <th :class="{ 'ps-4': !canMultipleDelete }">{{ t('wallet.rules.name') }}</th>
                                <th>{{ t('wallet.rules.percent') }}</th>
                                <th>{{ t('wallet.rules.applies_to') }}</th>
                                <th>{{ t('wallet.rules.period') }}</th>
                                <th>{{ t('wallet.rules.budget') }}</th>
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
                                            <i class="ri-percent-line fs-2 text-primary"></i>
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
                                                <i class="ri-percent-line text-primary"></i>
                                            </span>
                                            <div>
                                                <button v-if="canUpdate" type="button" class="btn btn-link p-0 text-start fw-semibold text-default" @click="openEdit(row)">
                                                    {{ row.name || `#${row.id}` }}
                                                </button>
                                                <span v-else class="fw-semibold text-default">{{ row.name || `#${row.id}` }}</span>
                                                <span class="d-block text-muted fs-11">#{{ row.id }} · {{ t('wallet.rules.priority') }}: {{ row.priority }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge" :class="row.kind === 'bonus' ? 'bg-pink-transparent' : 'bg-primary-transparent'">
                                            {{ row.kind === 'bonus' ? t('wallet.rules.bonus') : t('wallet.rules.fee') }}
                                        </span>
                                        <span class="fw-semibold ms-1" dir="ltr">{{ Number(row.percent) }}%</span>
                                    </td>
                                    <td class="fs-13">
                                        {{ row.country_id ? (row.country_code || `#${row.country_id}`) : t('wallet.rules.any_country') }} ·
                                        {{ row.payment_method_id ? (row.payment_method_name || `#${row.payment_method_id}`) : t('wallet.rules.any_method') }} ·
                                        {{ row.owner_type ? t(`wallet.owner.${row.owner_type}`) : t('wallet.rules.any_owner') }}
                                    </td>
                                    <td class="fs-12">{{ period(row) }}</td>
                                    <td class="fs-12">
                                        <template v-if="row.budget_total_minor != null">
                                            {{ fmtMinor(row.budget_used_minor) }} / {{ fmtMinor(row.budget_total_minor) }}
                                        </template>
                                        <span v-else class="text-muted">—</span>
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
                                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('wallet.rules.edit')" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                            <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('wallet.common.delete')" @click="confirmDelete(row.id)"><i class="ri-delete-bin-line"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination v-model:per-page="perPage" :pagination="pagination" @change="fetch" />
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
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import ConfirmDeleteModal from '../../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import { useConfirmDelete } from '../../../../../../../composables/useConfirmDelete';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import useWalletStatusTabs from '../../../../../../../composables/useWalletStatusTabs';
import WalletStatusTabs from '../../../../../../../components/wallet/WalletStatusTabs.vue';
import { usePermission } from '../../../../../../../composables/usePermission';
import { fmtMinor } from '../../../../../../../utils/walletMoney';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const canCreate = computed(() => can('wallet-fee-rules.create'));
const canUpdate = computed(() => can('wallet-fee-rules.update'));
const canDelete = computed(() => can('wallet-fee-rules.delete'));
const canChangeStatus = computed(() => can('wallet-fee-rules.change-status'));
const canMultipleDelete = computed(() => can('wallet-fee-rules.multiple-delete'));

const { rows, loading, pagination, filters, fetch, perPage, statusCounts } = useWalletList('wallet-fee-rules', { defaults: { search: '', filterColumns: '' }, statusCounts: true });
const { statusFilter, counts, setStatusFilter } = useWalletStatusTabs(filters, statusCounts);

const tableColumnCount = computed(() => 7 + (canMultipleDelete.value ? 1 : 0));

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


function period(row) {
    if (! row.starts_at && ! row.ends_at) {
        return t('wallet.rules.always');
    }

    const d = (v) => (v ? new Date(v).toLocaleDateString() : '…');

    return `${d(row.starts_at)} → ${d(row.ends_at)}`;
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
        await adminAxios.patch(`/api/admin/v1/wallet-fee-rules/${row.id}/status`, { status: ! row.status });
        row.status = ! row.status;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

function confirmDelete(id) {
    deleteConfirm.open({
        title: t('wallet.rules.delete_title'),
        message: t('wallet.rules.confirm_delete'),
        payload: { type: 'delete-single', id },
    });
}

function confirmDeleteSelected() {
    deleteConfirm.open({
        title: t('wallet.rules.delete_selected_title'),
        message: t('wallet.rules.confirm_delete_selected'),
        payload: { type: 'delete-multiple' },
    });
}

async function handleDeleteConfirm() {
    deleteConfirm.setLoading(true);

    try {
        const payload = deleteConfirm.state.payload;

        if (payload?.type === 'delete-multiple') {
            await adminAxios.post('/api/admin/v1/wallet-fee-rules/delete-multiple', { ids: selectedIds.value });
        } else {
            await adminAxios.delete(`/api/admin/v1/wallet-fee-rules/${payload.id}`);
        }

        showSuccess(t('wallet.rules.deleted'));
        selectedIds.value = [];
        fetch();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
    }
}

// Opening the page is one request: the list (with the tab counts). The countries / payment methods the form
// needs are loaded by the form itself when it opens.
onMounted(() => fetch(1));
</script>
