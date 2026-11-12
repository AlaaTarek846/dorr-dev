<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <h1 class="page-title fw-semibold fs-18 mb-0">
                    {{ t('ai_site_hosting.title') }}
                    <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">
                        {{ pagination.total }}
                    </span>
                </h1>
                <span class="fs-semibold text-muted">{{ t('ai_site_hosting.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_site_hosting.title') }}</li>
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
                                <input v-model="search" type="search" class="form-control" :placeholder="t('ai_site_hosting.plans_search')">
                                <button v-if="search" type="button" class="btn btn-light border catalog-search-clear" :title="t('ai_site_offers.clear_search')" @click="search = ''">
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button
                                v-if="statusFilter !== 'all'"
                                type="button"
                                class="btn btn-sm catalog-filter-btn catalog-filter-btn--all-idle"
                                @click="setStatusFilter('all')"
                            >
                                {{ t('ai_site_offers.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('ai_site_offers.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('ai_site_offers.filter_inactive') }} ({{ counts.inactive }})
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
                                {{ t('ai_site_hosting.add') }}
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
                                                :disabled="loading || !plans.length"
                                                :aria-label="t('ai_site_offers.select_all')"
                                                @change="toggleSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('ai_site_offers.name') }}</th>
                                        <th scope="col">{{ t('ai_site_offers.code') }}</th>
                                        <th scope="col">{{ t('ai_site_hosting.period') }}</th>
                                        <th scope="col">{{ t('ai_site_offers.countries') }}</th>
                                        <th scope="col">{{ t('ai_site_offers.status') }}</th>
                                        <th scope="col">{{ t('ai_site_offers.created_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_site_offers.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="tableColumnCount" />

                                    <tr v-else-if="!plans.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-server-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('ai_site_hosting.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('ai_site_hosting.empty') }}</p>
                                                <button v-if="canCreate && !isDeletedView" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>{{ t('ai_site_hosting.add') }}
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
                                                <span class="d-block text-muted fs-11">#{{ plan.id }}</span>
                                            </td>
                                            <td><span class="badge bg-primary-transparent" dir="ltr">{{ plan.code }}</span></td>
                                            <td>
                                                <span class="badge" :class="plan.period === 'yearly' ? 'bg-info-transparent' : 'bg-secondary-transparent'">{{ t('ai_site_hosting.period_' + plan.period) }}</span>
                                            </td>
                                            <td>
                                                <span v-if="plan.prices_count" class="badge bg-success-transparent">{{ plan.prices_count }}</span>
                                                <span v-else class="badge bg-warning-transparent">{{ t('ai_site_offers.no_prices') }}</span>
                                            </td>
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
                                                    {{ plan.status ? t('ai_site_offers.filter_active') : t('ai_site_offers.filter_inactive') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatCatalogDate(catalogPrimaryDate(plan), locale) }}</span>
                                                <span v-if="catalogShowUpdatedSubtext(plan)" class="d-block text-muted fs-11">
                                                    {{ t('ai_site_offers.updated') }}: {{ formatCatalogDate(plan.updated_at, locale) }}
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
                                                        <i class="ri-earth-line me-1 align-middle"></i>{{ t('ai_site_offers.manage_prices') }}
                                                    </button>
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('ai_site_hosting.edit')" @click="openEdit(plan)">
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('ai_site_hosting.delete_title')" @click="confirmDelete(plan.id)">
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
                                <label class="text-muted fs-13 mb-0" for="ai-site-hosting-plans-per-page">{{ t('ai_site_offers.per_page') }}</label>
                                <select id="ai-site-hosting-plans-per-page" v-model.number="perPage" class="form-select form-select-sm w-auto">
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav :aria-label="t('ai_site_hosting.title')" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">{{ t('ai_site_offers.previous') }}</button>
                                    </li>
                                    <li v-for="page in pageNumbers" :key="page" class="page-item" :class="{ active: page === currentPage }">
                                        <button type="button" class="page-link" @click="changePage(page)">{{ page }}</button>
                                    </li>
                                    <li class="page-item" :class="{ disabled: !pagination.next_page_url }">
                                        <button type="button" class="page-link text-primary" @click="changePage(currentPage + 1)">{{ t('ai_site_offers.next') }}</button>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ModalHostingPlan :show="modalShow" :type="modalType" :record="selectedRecord" @close="modalShow = false" @saved="onSaved" />

        <ModalHostingPlanPrices :show="pricesModalShow" :offer="pricesOffer" :can-edit="canUpdate" @close="pricesModalShow = false" @saved="fetchPlans(currentPage)" />

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
import ConfirmDeleteModal from '../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import { useAiSiteHostingPlans } from '../../../../../../composables/useAiSiteHostingPlans';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useCatalogTrashActions } from '../../../../../../composables/useCatalogTrashActions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useAiSiteHostingPlansStore } from '../../../../../../stores/aiSiteHostingPlans';
import {
    catalogPrimaryDate,
    catalogShowDeletedSubtext,
    catalogShowUpdatedSubtext,
    formatCatalogDate,
    isTrashedRecord,
} from '../../../../../../utils/catalog';
import ModalHostingPlan from './ModalHostingPlan.vue';
import ModalHostingPlanPrices from './ModalHostingPlanPrices.vue';

const { t, locale } = useI18n();
const plansStore = useAiSiteHostingPlansStore();

const { canCreate, canUpdate, canDelete, canChangeStatus, canMultipleDelete } = useCatalogPermissions('ai-site-hosting-plans');

const plansApi = useAiSiteHostingPlans();
const { plans, loading, pagination, selectedIds, currentPage, perPage, search, statusFilter, isDeletedView } = plansApi;
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

const tableColumnCount = computed(() => 7 + (canMultipleDelete.value ? 1 : 0));

const counts = computed(() => ({
    total: plansStore.total ?? pagination.value?.total ?? 0,
    active: plansStore.activeCount ?? 0,
    inactive: plansStore.inactiveCount ?? 0,
    deleted: plansStore.deletedCount ?? 0,
}));

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const pricesModalShow = ref(false);
const pricesOffer = ref(null);
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
    ? t('ai_site_offers.showing_entries', { from: pagination.value.from ?? 0, to: pagination.value.to ?? 0, total: pagination.value.total ?? 0 })
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
    pricesOffer.value = plan;
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
    i18nPrefix: 'ai_site_hosting',
});

function onSaved() {
    modalShow.value = false;
    fetchPlans(currentPage.value);
}

onMounted(() => fetchPlans());
</script>
