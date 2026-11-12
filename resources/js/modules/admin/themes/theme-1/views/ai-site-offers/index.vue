<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <h1 class="page-title fw-semibold fs-18 mb-0">
                    {{ pageTitle }}
                    <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">
                        {{ pagination.total }}
                    </span>
                </h1>
                <span class="fs-semibold text-muted">{{ pageSubtitle }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ pageTitle }}</li>
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
                                <input v-model="search" type="search" class="form-control" :placeholder="t('ai_site_offers.search')">
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
                                {{ addLabel }}
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
                                                :disabled="loading || !offers.length"
                                                :aria-label="t('ai_site_offers.select_all')"
                                                @change="toggleSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('ai_site_offers.name') }}</th>
                                        <th scope="col">{{ t('ai_site_offers.code') }}</th>
                                        <th scope="col">{{ t('ai_site_offers.generations_included') }}</th>
                                        <th scope="col">{{ t('ai_site_offers.countries') }}</th>
                                        <th scope="col">{{ t('ai_site_offers.status') }}</th>
                                        <th scope="col">{{ t('ai_site_offers.created_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_site_offers.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="tableColumnCount" />

                                    <tr v-else-if="!offers.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="fs-2 text-primary" :class="isPack ? 'ri-edit-box-line' : 'ri-global-line'"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ isPack ? t('ai_site_offers.packs_empty_title') : t('ai_site_offers.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('ai_site_offers.empty') }}</p>
                                                <button v-if="canCreate && !isDeletedView" type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>{{ addLabel }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr v-for="offer in offers" :key="offer.id">
                                            <td v-if="canMultipleDelete" class="ps-4">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    :checked="isSelected(offer.id)"
                                                    :aria-label="offer.name"
                                                    @change="toggleSelect(offer.id, $event.target.checked)"
                                                >
                                            </td>
                                            <td>
                                                <button
                                                    v-if="canUpdate && !isTrashedRecord(offer)"
                                                    type="button"
                                                    class="btn btn-link p-0 text-start fw-semibold text-default"
                                                    @click="openEdit(offer)"
                                                >
                                                    {{ offer.name }}
                                                </button>
                                                <span v-else class="fw-semibold text-default">{{ offer.name }}</span>
                                                <span class="d-block text-muted fs-11">#{{ offer.id }}</span>
                                            </td>
                                            <td><span class="badge bg-primary-transparent" dir="ltr">{{ offer.code }}</span></td>
                                            <td>{{ offer.generations_included }}</td>
                                            <td>
                                                <span v-if="offer.prices_count" class="badge bg-success-transparent">{{ offer.prices_count }}</span>
                                                <span v-else class="badge bg-warning-transparent">{{ t('ai_site_offers.no_prices') }}</span>
                                            </td>
                                            <td>
                                                <span v-if="isTrashedRecord(offer)" class="badge bg-danger-transparent">{{ t('catalog.deleted_badge') }}</span>
                                                <div
                                                    v-else-if="canChangeStatus"
                                                    class="toggle toggle-success mb-0 catalog-status-toggle"
                                                    :class="{ on: offer.status, 'catalog-status-toggle--loading': isTogglingStatus(offer.id) }"
                                                    role="switch"
                                                    tabindex="0"
                                                    :aria-checked="offer.status"
                                                    :aria-busy="isTogglingStatus(offer.id)"
                                                    @click="toggleStatus(offer)"
                                                    @keydown.enter.space.prevent="toggleStatus(offer)"
                                                >
                                                    <span></span>
                                                </div>
                                                <span v-else class="badge" :class="offer.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                    {{ offer.status ? t('ai_site_offers.filter_active') : t('ai_site_offers.filter_inactive') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatCatalogDate(catalogPrimaryDate(offer), locale) }}</span>
                                                <span v-if="catalogShowUpdatedSubtext(offer)" class="d-block text-muted fs-11">
                                                    {{ t('ai_site_offers.updated') }}: {{ formatCatalogDate(offer.updated_at, locale) }}
                                                </span>
                                                <span v-if="catalogShowDeletedSubtext(offer, isDeletedView)" class="d-block text-muted fs-11">
                                                    {{ t('catalog.deleted_at') }}: {{ formatCatalogDate(offer.deleted_at, locale) }}
                                                </span>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div v-if="isTrashedRecord(offer)" class="btn-list justify-content-end">
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-success-light btn-icon" :title="t('catalog.restore_title')" @click="confirmRestore(offer.id)">
                                                        <i class="ri-arrow-go-back-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('catalog.force_delete_title')" @click="confirmForceDelete(offer.id)">
                                                        <i class="ri-delete-bin-7-line"></i>
                                                    </button>
                                                </div>
                                                <div v-else class="btn-list justify-content-end">
                                                    <button type="button" class="btn btn-sm btn-primary-light" @click="openPrices(offer)">
                                                        <i class="ri-earth-line me-1 align-middle"></i>{{ t('ai_site_offers.manage_prices') }}
                                                    </button>
                                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="editLabel" @click="openEdit(offer)">
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('ai_site_offers.delete_title')" @click="confirmDelete(offer.id)">
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
                                <label class="text-muted fs-13 mb-0" for="ai-site-offers-per-page">{{ t('ai_site_offers.per_page') }}</label>
                                <select id="ai-site-offers-per-page" v-model.number="perPage" class="form-select form-select-sm w-auto">
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav :aria-label="pageTitle" class="pagination-style-4">
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

        <ModalOffer :show="modalShow" :kind="kind" :type="modalType" :record="selectedRecord" @close="modalShow = false" @saved="onSaved" />

        <ModalOfferPrices :show="pricesModalShow" :offer="pricesOffer" :can-edit="canUpdate" @close="pricesModalShow = false" @saved="fetchOffers(currentPage)" />

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
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import ConfirmDeleteModal from '../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import { useAiSiteOffers } from '../../../../../../composables/useAiSiteOffers';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useCatalogTrashActions } from '../../../../../../composables/useCatalogTrashActions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useAiSiteOffersStore } from '../../../../../../stores/aiSiteOffers';
import {
    catalogPrimaryDate,
    catalogShowDeletedSubtext,
    catalogShowUpdatedSubtext,
    formatCatalogDate,
    isTrashedRecord,
} from '../../../../../../utils/catalog';
import ModalOffer from './ModalOffer.vue';
import ModalOfferPrices from './ModalOfferPrices.vue';

const { t, locale } = useI18n();
const route = useRoute();

// One screen, two routes: admin.ai-site-offers.index (whole sites) and admin.ai-site-edit-packs.index (meta.kind).
const kind = computed(() => (route.meta.kind === 'edit_pack' ? 'edit_pack' : 'site'));
const isPack = computed(() => kind.value === 'edit_pack');
const pageTitle = computed(() => t(isPack.value ? 'ai_site_offers.packs_title' : 'ai_site_offers.title'));
const pageSubtitle = computed(() => t(isPack.value ? 'ai_site_offers.packs_subtitle' : 'ai_site_offers.subtitle'));
const addLabel = computed(() => t(isPack.value ? 'ai_site_offers.add_pack' : 'ai_site_offers.add'));
const editLabel = computed(() => t(isPack.value ? 'ai_site_offers.edit_pack' : 'ai_site_offers.edit'));

const offersStore = useAiSiteOffersStore();

const { canCreate, canUpdate, canDelete, canChangeStatus, canMultipleDelete } = useCatalogPermissions('ai-site-offers');

const offersApi = useAiSiteOffers({ getKind: () => kind.value });
const { offers, loading, pagination, selectedIds, currentPage, perPage, search, statusFilter, isDeletedView } = offersApi;
const {
    fetchOffers,
    setStatusFilter,
    deleteOffer,
    deleteSelected,
    restoreOffer,
    forceDeleteOffer,
    forceDeleteSelected,
    toggleStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = offersApi;

const tableColumnCount = computed(() => 7 + (canMultipleDelete.value ? 1 : 0));

const counts = computed(() => ({
    total: offersStore.total ?? pagination.value?.total ?? 0,
    active: offersStore.activeCount ?? 0,
    inactive: offersStore.inactiveCount ?? 0,
    deleted: offersStore.deletedCount ?? 0,
}));

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const pricesModalShow = ref(false);
const pricesOffer = ref(null);
const deleteConfirm = useConfirmDelete();

const selectedCount = computed(() => selectedIds.value.length);

const allSelected = computed(() => (
    offers.value.length > 0 && offers.value.every((offer) => selectedIds.value.includes(Number(offer.id)))
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

function openEdit(offer) {
    modalType.value = 'edit';
    selectedRecord.value = { ...offer };
    modalShow.value = true;
}

function openPrices(offer) {
    pricesOffer.value = offer;
    pricesModalShow.value = true;
}

function changePage(page) {
    if (pagination.value && page >= 1 && page <= pagination.value.last_page) {
        fetchOffers(page);
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
    deleteItem: deleteOffer,
    restoreItem: restoreOffer,
    forceDeleteItem: forceDeleteOffer,
    forceDeleteSelected,
    i18nPrefix: 'ai_site_offers',
});

function onSaved() {
    modalShow.value = false;
    fetchOffers(currentPage.value);
}

// Moving between "offers" and "edit packs" keeps this component: start that screen fresh (its own list and counts).
watch(kind, () => {
    offersStore.$reset();
    search.value = '';
    statusFilter.value = 'all';
    fetchOffers(1);
});

onMounted(() => fetchOffers());
</script>
