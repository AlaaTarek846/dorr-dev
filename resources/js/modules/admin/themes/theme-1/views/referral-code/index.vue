<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('referral_codes.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('referral_codes.title') }}</li>
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
                                    :placeholder="t('referral_codes.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('referral_codes.clear_search')"
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
                                {{ t('referral_codes.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('referral_codes.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="statusFilter === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('referral_codes.filter_inactive') }} ({{ counts.inactive }})
                            </button>

                            <MultiSelect
                                v-model="ownerTypeFilter"
                                :options="ownerTypeOptions"
                                option-label="label"
                                option-value="value"
                                :placeholder="t('referral_codes.filter_all_owners')"
                                filter
                                :filter-placeholder="t('search_placeholder')"
                                display="chip"
                                :max-selected-labels="2"
                                append-to="body"
                                class="wallet-filter-select"
                            />
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col" class="ps-4">{{ t('referral_codes.owner') }}</th>
                                        <th scope="col">{{ t('referral_codes.code') }}</th>
                                        <th scope="col">{{ t('referral_codes.owner_type') }}</th>
                                        <th scope="col">{{ t('referral_codes.status') }}</th>
                                        <th scope="col">{{ t('referral_codes.count') }}</th>
                                        <th scope="col">{{ t('referral_codes.created') }}</th>
                                        <th v-if="showActionsColumn" scope="col" class="text-end pe-4">{{ t('referral_codes.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="tableColumnCount" />

                                    <tr v-else-if="!codes.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-coupon-3-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('referral_codes.empty_title') }}</p>
                                                <p class="text-muted mb-0">{{ t('referral_codes.empty') }}</p>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                    <tr
                                        v-for="row in codes"
                                        :key="row.id"
                                        class="crm-contact"
                                    >
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="avatar avatar-sm avatar-rounded bg-primary-transparent">
                                                    <i :class="ownerIcon(row.owner?.type)" class="text-primary"></i>
                                                </span>
                                                <div>
                                                    <button
                                                        v-if="canView"
                                                        type="button"
                                                        class="btn btn-link p-0 text-start fw-semibold text-default"
                                                        @click="openShow(row.id)"
                                                    >
                                                        {{ ownerName(row) }}
                                                    </button>
                                                    <span v-else class="fw-semibold text-default">{{ ownerName(row) }}</span>
                                                    <span class="d-block text-muted fs-11">
                                                        #{{ row.id }}
                                                        <span v-if="row.owner?.phone" class="ms-1 users-phone" dir="ltr">{{ row.owner.phone }}</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-transparent">{{ row.code }}</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-transparent">{{ ownerLabel(row.owner?.type) }}</span>
                                        </td>
                                        <td>
                                            <div
                                                v-if="canChangeStatus"
                                                class="toggle toggle-success mb-0 catalog-status-toggle"
                                                :class="{
                                                    on: row.is_active,
                                                    'catalog-status-toggle--loading': isTogglingStatus(row.id),
                                                }"
                                                role="button"
                                                tabindex="0"
                                                :aria-busy="isTogglingStatus(row.id)"
                                                @click="toggleStatus(row)"
                                                @keydown.enter.space.prevent="toggleStatus(row)"
                                            >
                                                <span></span>
                                            </div>
                                            <span v-else class="badge" :class="row.is_active ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                {{ row.is_active ? t('referral_codes.active') : t('referral_codes.inactive') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-transparent">{{ row.referrals_count ?? 0 }}</span>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ formatCatalogDate(row.created_at, locale) }}</span>
                                        </td>
                                        <td v-if="showActionsColumn" class="text-end pe-4">
                                            <div class="btn-list justify-content-end">
                                                <button
                                                    v-if="canView"
                                                    type="button"
                                                    class="btn btn-sm btn-info-light btn-icon"
                                                    :title="t('referral_codes.view')"
                                                    @click="openShow(row.id)"
                                                >
                                                    <i class="ri-eye-line"></i>
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
                                <label class="text-muted fs-13 mb-0" for="referral-codes-per-page">{{ t('referral_codes.per_page') }}</label>
                                <select
                                    id="referral-codes-per-page"
                                    v-model.number="perPage"
                                    class="form-select form-select-sm w-auto"
                                >
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="Referral codes pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('referral_codes.previous') }}
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
                                            {{ t('referral_codes.next') }}
                                        </button>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ModalShow :show="modalShow" :record="selectedRecord" @close="closeModal" />
    </div>
</template>

<script setup>
import MultiSelect from 'primevue/multiselect';
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useReferralCodes } from '../../../../../../composables/useReferralCodes';
import { formatCatalogDate } from '../../../../../../utils/catalog';
import ModalShow from './ModalShow.vue';

const { t, locale } = useI18n();
const { canView, canChangeStatus } = useCatalogPermissions('referral-codes');
const showActionsColumn = computed(() => canView.value);

const tableColumnCount = computed(() => (showActionsColumn.value ? 7 : 6));

const api = useReferralCodes();
const {
    codes,
    loading,
    pagination,
    perPage,
    currentPage,
    search,
    ownerTypeFilter,
    statusFilter,
    counts,
    fetchCodes,
    setStatusFilter,
    toggleStatus,
    isTogglingStatus,
    selectedRecord,
    modalShow,
    showRecord,
    closeModal,
} = api;

const showAllFilter = computed(() => statusFilter.value !== 'all');

const ownerTypeOptions = computed(() => [
    { value: 'user', label: t('referral_codes.type_user') },
    { value: 'provider', label: t('referral_codes.type_provider') },
]);

const paginationArrowIcon = computed(() => (
    locale.value === 'ar'
        ? 'ri-arrow-left-s-line fw-semibold'
        : 'ri-arrow-right-s-line fw-semibold'
));

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

    return t('referral_codes.showing_entries', {
        from: pagination.value.from ?? 0,
        to: pagination.value.to ?? 0,
        total: pagination.value.total ?? 0,
    });
});

function ownerName(row) {
    return row.owner?.name || `#${row.owner?.id ?? row.id}`;
}

function ownerLabel(type) {
    if (type === 'user') {
        return t('referral_codes.type_user');
    }

    if (type === 'provider') {
        return t('referral_codes.type_provider');
    }

    return type || '—';
}

function ownerIcon(type) {
    return type === 'provider' ? 'ri-store-2-line' : 'ri-user-line';
}

function clearSearch() {
    search.value = '';
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchCodes(page);
}

async function openShow(id) {
    await showRecord(id);
}

onMounted(() => {
    fetchCodes();
});
</script>
