<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <h1 class="page-title fw-semibold fs-18 mb-0">
                    {{ t('ai_plans.title') }}
                    <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">
                        {{ pagination.total }}
                    </span>
                </h1>
                <span class="fs-semibold text-muted">{{ t('ai_plans.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_plans.title') }}</li>
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
                                <input v-model="search" type="search" class="form-control" :placeholder="t('ai_plans.search')">
                                <button v-if="search" type="button" class="btn btn-light border catalog-search-clear" :title="t('ai_plans.clear_search')" @click="search = ''">
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button
                                v-if="statusFilter !== 'all'"
                                type="button"
                                class="btn btn-sm catalog-filter-btn catalog-filter-btn--all-idle"
                                @click="setStatusFilter('all')"
                            >
                                {{ t('ai_plans.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('ai_plans.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('ai_plans.filter_inactive') }} ({{ counts.inactive }})
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
                            <button v-if="canCreate && statusFilter !== 'deleted'" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ t('ai_plans.add_short') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="!loading && noActiveTrial && !isDeletedView" class="alert alert-warning rounded-0 border-start-0 border-end-0 mb-0 py-2 px-4 fs-13">
                        <i class="ri-alarm-warning-line me-1 align-middle"></i>{{ t('ai_plans.no_trial_warning') }}
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
                                                :disabled="loading || !plans.length"
                                                :aria-label="t('ai_plans.select_all')"
                                                @change="toggleSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('ai_plans.name') }}</th>
                                        <th scope="col">{{ t('ai_plans.code') }}</th>
                                        <th scope="col">{{ t('ai_plans.usage_minutes') }}</th>
                                        <th scope="col">{{ t('ai_plans.price') }}</th>
                                        <th scope="col">{{ t('ai_plans.duration_days') }}</th>
                                        <th scope="col">{{ t('ai_plans.status') }}</th>
                                        <th scope="col">{{ t('ai_plans.created_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_plans.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="tableColumnCount" />

                                    <tr v-else-if="!plans.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-vip-crown-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('ai_plans.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('ai_plans.empty') }}</p>
                                                <button v-if="canCreate && !isDeletedView" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>{{ t('ai_plans.add_short') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr v-for="plan in plans" :key="plan.id">
                                            <td v-if="canMultipleDelete" class="ps-4">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    :checked="isSelected(plan.id)"
                                                    :aria-label="plan.name"
                                                    @change="toggleSelect(plan.id, $event.target.checked)"
                                                >
                                            </td>
                                            <td>
                                                <button
                                                    v-if="canUpdate && !isTrashedRecord(plan)"
                                                    type="button"
                                                    class="btn btn-link p-0 text-start fw-semibold text-default"
                                                    @click="openEdit(plan)"
                                                >
                                                    {{ plan.name }}
                                                </button>
                                                <span v-else class="fw-semibold text-default">{{ plan.name }}</span>
                                                <span class="d-flex flex-wrap gap-1 mt-1 align-items-center">
                                                    <span class="text-muted fs-11">#{{ plan.id }}</span>
                                                    <span v-if="plan.is_trial" class="badge bg-warning-transparent">{{ t('ai_plans.is_trial') }}</span>
                                                    <span v-if="plan.is_featured" class="badge bg-primary-transparent"><i class="ri-star-fill align-middle"></i></span>
                                                    <span v-if="plan.badge" class="badge bg-info-transparent">{{ plan.badge }}</span>
                                                    <span v-if="plan.discount_percent" class="badge bg-success-transparent">{{ t('ai_plans.discount_badge', { percent: plan.discount_percent }) }}</span>
                                                </span>
                                            </td>
                                            <td><span class="badge bg-primary-transparent" dir="ltr">{{ plan.code }}</span></td>
                                            <td>
                                                <span v-if="plan.usage_minutes > 0">{{ plan.usage_minutes }}</span>
                                                <span v-else class="badge bg-success-transparent">{{ t('ai_plans.unlimited') }}</span>
                                            </td>
                                            <td>
                                                <span class="fw-semibold">{{ plan.price }}</span> {{ plan.currency }}
                                                <span v-if="plan.original_price" class="d-block text-muted fs-11 text-decoration-line-through">{{ plan.original_price }} {{ plan.currency }}</span>
                                            </td>
                                            <td>{{ plan.duration_days }} {{ t('ai_plans.days_suffix') }}</td>
                                            <td>
                                                <span v-if="isTrashedRecord(plan)" class="badge bg-danger-transparent">{{ t('catalog.deleted_badge') }}</span>
                                                <div
                                                    v-else-if="canChangeStatus"
                                                    class="toggle toggle-success mb-0 catalog-status-toggle"
                                                    :class="{ on: plan.status, 'catalog-status-toggle--loading': isTogglingStatus(plan.id) }"
                                                    role="switch"
                                                    tabindex="0"
                                                    :aria-checked="plan.status"
                                                    :aria-busy="isTogglingStatus(plan.id)"
                                                    @click="toggleStatus(plan)"
                                                    @keydown.enter.space.prevent="toggleStatus(plan)"
                                                >
                                                    <span></span>
                                                </div>
                                                <span v-else class="badge" :class="plan.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                    {{ plan.status ? t('ai_plans.filter_active') : t('ai_plans.filter_inactive') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatCatalogDate(catalogPrimaryDate(plan), locale) }}</span>
                                                <span v-if="catalogShowUpdatedSubtext(plan)" class="d-block text-muted fs-11">
                                                    {{ t('ai_plans.updated') }}: {{ formatCatalogDate(plan.updated_at, locale) }}
                                                </span>
                                                <span v-if="catalogShowDeletedSubtext(plan, isDeletedView)" class="d-block text-muted fs-11">
                                                    {{ t('catalog.deleted_at') }}: {{ formatCatalogDate(plan.deleted_at, locale) }}
                                                </span>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div v-if="isTrashedRecord(plan)" class="btn-list justify-content-end">
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-success-light btn-icon" :title="t('catalog.restore_title')" @click="confirmRestore(plan.id)">
                                                        <i class="ri-arrow-go-back-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('catalog.force_delete_title')" @click="confirmForceDelete(plan.id)">
                                                        <i class="ri-delete-bin-7-line"></i>
                                                    </button>
                                                </div>
                                                <div v-else class="btn-list justify-content-end">
                                                    <button type="button" class="btn btn-sm btn-primary-light" @click="openPrices(plan)">
                                                        <i class="ri-earth-line me-1 align-middle"></i>{{ t('ai_plans.manage_prices') }}
                                                    </button>
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('ai_plans.edit_title')" @click="openEdit(plan)">
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('ai_plans.delete_title')" @click="confirmDelete(plan.id)">
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
                                <label class="text-muted fs-13 mb-0" for="ai-plans-per-page">{{ t('ai_plans.per_page') }}</label>
                                <select id="ai-plans-per-page" v-model.number="perPage" class="form-select form-select-sm w-auto">
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav :aria-label="t('ai_plans.title')" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">{{ t('ai_plans.previous') }}</button>
                                    </li>
                                    <li v-for="page in pageNumbers" :key="page" class="page-item" :class="{ active: page === currentPage }">
                                        <button type="button" class="page-link" @click="changePage(page)">{{ page }}</button>
                                    </li>
                                    <li class="page-item" :class="{ disabled: !pagination.next_page_url }">
                                        <button type="button" class="page-link text-primary" @click="changePage(currentPage + 1)">{{ t('ai_plans.next') }}</button>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ModalCreateAndUpdate :show="modalShow" :type="modalType" :record="selectedRecord" @close="modalShow = false" @saved="onSaved" />

        <ModalManagePrices :show="pricesModalShow" :plan="pricesPlan" :can-edit="canUpdate" @close="pricesModalShow = false" />

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
import { useAiPlans } from '../../../../../../composables/useAiPlans';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useCatalogTrashActions } from '../../../../../../composables/useCatalogTrashActions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useAiPlansStore } from '../../../../../../stores/aiPlans';
import {
    catalogPrimaryDate,
    catalogShowDeletedSubtext,
    catalogShowUpdatedSubtext,
    formatCatalogDate,
    isTrashedRecord,
} from '../../../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';
import ModalManagePrices from './ModalManagePrices.vue';

const { t, locale } = useI18n();
const plansStore = useAiPlansStore();

const { canCreate, canUpdate, canDelete, canChangeStatus, canMultipleDelete } = useCatalogPermissions('ai-plans');

const plansApi = useAiPlans();
const { plans, loading, pagination, selectedIds, currentPage, perPage, search, statusFilter, isDeletedView } = storeToRefs(plansApi);
const {
    fetchPlans,
    setStatusFilter,
    deletePlan,
    deleteSelected,
    restorePlan,
    forceDeletePlan,
    forceDeleteSelected,
    toggleStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = plansApi;

const tableColumnCount = computed(() => 8 + (canMultipleDelete.value ? 1 : 0));

const counts = computed(() => ({
    total: plansStore.total ?? pagination.value?.total ?? 0,
    active: plansStore.activeCount ?? 0,
    inactive: plansStore.inactiveCount ?? 0,
    deleted: plansStore.deletedCount ?? 0,
}));

// The free trial is what a brand-new user gets; without an active one they are locked out (AiChatUsageGuard).
// Only judged on the unfiltered first page, where every plan of a normal-sized catalog is listed.
const noActiveTrial = computed(() => (
    statusFilter.value === 'all' && ! search.value && (pagination.value?.last_page ?? 1) === 1
    && plans.value.length > 0 && ! plans.value.some((plan) => plan.is_trial && plan.status)
));

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const pricesModalShow = ref(false);
const pricesPlan = ref(null);
const deleteConfirm = useConfirmDelete();

const selectedCount = computed(() => selectedIds.value.length);

const allSelected = computed(() => (
    plans.value.length > 0 && plans.value.every((plan) => selectedIds.value.includes(Number(plan.id)))
));

function isSelected(id) {
    return selectedIds.value.includes(Number(id));
}

const pageNumbers = computed(() => {
    const total = pagination.value?.last_page ?? 0;
    let start = Math.max(1, currentPage.value - 2);
    const end = Math.min(total, start + 4);
    start = Math.max(1, end - 4);

    return total ? Array.from({ length: end - start + 1 }, (_, i) => start + i) : [];
});

const entriesLabel = computed(() => (pagination.value
    ? t('ai_plans.showing_entries', { from: pagination.value.from ?? 0, to: pagination.value.to ?? 0, total: pagination.value.total ?? 0 })
    : ''));

function openCreate() {
    modalType.value = 'create';
    selectedRecord.value = null;
    modalShow.value = true;
}

function openEdit(plan) {
    modalType.value = 'edit';
    selectedRecord.value = { ...plan };
    modalShow.value = true;
}

function openPrices(plan) {
    pricesPlan.value = plan;
    pricesModalShow.value = true;
}

function changePage(page) {
    if (pagination.value && page >= 1 && page <= pagination.value.last_page) {
        fetchPlans(page);
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
    deleteItem: deletePlan,
    restoreItem: restorePlan,
    forceDeleteItem: forceDeletePlan,
    forceDeleteSelected,
    i18nPrefix: 'ai_plans',
});

function onSaved() {
    modalShow.value = false;
    fetchPlans(currentPage.value);
}

onMounted(() => fetchPlans());
</script>
