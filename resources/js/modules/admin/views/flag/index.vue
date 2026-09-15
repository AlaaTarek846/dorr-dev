<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('flags.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('flags.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                        <div class="d-flex flex-wrap align-items-center gap-1 flags-toolbar-filters">
                            <div class="input-group input-group-sm flags-toolbar-search">
                                <span class="input-group-text bg-white">
                                    <i class="ri-search-line text-muted"></i>
                                </span>
                                <input
                                    v-model="search"
                                    type="search"
                                    class="form-control"
                                    :placeholder="t('flags.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border flags-search-clear"
                                    :title="t('flags.clear_search')"
                                    @click="clearSearch"
                                >
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button
                                v-if="showAllFilter"
                                type="button"
                                class="btn btn-sm flags-filter-btn"
                                :class="statusFilter === 'all' ? 'flags-filter-btn--all' : 'flags-filter-btn--all-idle'"
                                @click="setStatusFilter('all')"
                            >
                                {{ t('flags.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm flags-filter-btn"
                                :class="statusFilter === 'active' ? 'flags-filter-btn--active' : 'flags-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('flags.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm flags-filter-btn"
                                :class="statusFilter === 'inactive' ? 'flags-filter-btn--inactive' : 'flags-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('flags.filter_inactive') }} ({{ counts.inactive }})
                            </button>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <button
                                v-if="selectedCount"
                                type="button"
                                class="btn btn-danger btn-sm btn-wave"
                                @click="confirmDeleteSelected"
                            >
                                <i class="ri-delete-bin-line me-1 align-middle"></i>
                                {{ t('flags.delete_count', { count: selectedCount }) }}
                            </button>

                            <button type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ t('flags.add_short') }}
                            </button>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col" class="ps-4" style="width: 48px;">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="allSelected"
                                                :disabled="loading || !flags.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('flags.name') }}</th>
                                        <th scope="col">{{ t('flags.code') }}</th>
                                        <th scope="col">{{ t('flags.status') }}</th>
                                        <th scope="col">{{ t('flags.created_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('flags.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" />

                                    <tr v-else-if="!flags.length">
                                        <td colspan="6" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-flag-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('flags.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('flags.empty') }}</p>
                                                <button type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('flags.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                    <tr
                                        v-for="flag in flags"
                                        :key="flag.id"
                                        class="crm-contact"
                                    >
                                        <td class="ps-4">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="isSelected(flag.id)"
                                                @change="onRowSelect(flag.id, $event.target.checked)"
                                            >
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <img
                                                    :src="flagImageUrl(flag.code)"
                                                    :alt="flag.code"
                                                    class="flag-img"
                                                    width="32"
                                                    height="24"
                                                    @error="onFlagImageError"
                                                >
                                                <div>
                                                    <button
                                                        type="button"
                                                        class="btn btn-link p-0 text-start fw-semibold text-default"
                                                        @click="openEdit(flag)"
                                                    >
                                                        {{ displayName(flag) }}
                                                    </button>
                                                    <span class="d-block text-muted fs-11">
                                                        #{{ flag.id }}
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-transparent">{{ flag.code }}</span>
                                        </td>
                                        <td>
                                            <div
                                                class="toggle toggle-success mb-0 catalog-status-toggle"
                                                :class="{
                                                    on: flag.status,
                                                    'catalog-status-toggle--loading': isTogglingStatus(flag.id),
                                                }"
                                                role="button"
                                                tabindex="0"
                                                :aria-busy="isTogglingStatus(flag.id)"
                                                @click="toggleStatus(flag)"
                                                @keydown.enter.space.prevent="toggleStatus(flag)"
                                            >
                                                <span></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ formatDate(flag.created_at) }}</span>
                                            <span v-if="flag.updated_at" class="d-block text-muted fs-11">
                                                {{ t('flags.updated') }}: {{ formatDate(flag.updated_at) }}
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-list justify-content-end">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-info-light btn-icon"
                                                    :title="t('flags.edit_title')"
                                                    @click="openEdit(flag)"
                                                >
                                                    <i class="ri-pencil-line"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light btn-icon"
                                                    :title="t('flags.confirm_delete')"
                                                    @click="confirmDelete(flag.id)"
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
                                <label class="text-muted fs-13 mb-0" for="flags-per-page">{{ t('flags.per_page') }}</label>
                                <select
                                    id="flags-per-page"
                                    v-model.number="perPage"
                                    class="form-select form-select-sm w-auto"
                                >
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="Flags pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('flags.previous') }}
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
                                            {{ t('flags.next') }}
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
import { computed, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import ConfirmDeleteModal from '../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../components/ui/TableSkeleton.vue';
import { useConfirmDelete } from '../../../../composables/useConfirmDelete';
import { useFlags } from '../../../../composables/useFlags';
import { useFlagsStore } from '../../../../stores/flags';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const flagsStore = useFlagsStore();

const flagsApi = useFlags();
const {
    flags,
    loading,
    pagination,
    selectedIds,
    currentPage,
    perPage,
    search,
    statusFilter,
} = storeToRefs(flagsApi);
const {
    fetchFlags,
    setStatusFilter,
    deleteFlag,
    deleteSelected,
    toggleStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = flagsApi;

const counts = computed(() => ({
    total: flagsStore.total ?? pagination.value?.total ?? 0,
    active: flagsStore.activeCount ?? 0,
    inactive: flagsStore.inactiveCount ?? 0,
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

const allSelected = computed(() => {
    if (! flags.value.length) {
        return false;
    }

    return flags.value.every((flag) => selectedIds.value.includes(Number(flag.id)));
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

    return t('flags.showing_entries', {
        from: pagination.value.from ?? 0,
        to: pagination.value.to ?? 0,
        total: pagination.value.total ?? 0,
    });
});

function displayName(flag) {
    const translation = flag.translations?.find((item) => item.locale === locale.value);

    return translation?.name || flag.name || '-';
}

function flagImageUrl(code) {
    if (! code) {
        return '';
    }

    return `https://flagsapi.com/${code.toUpperCase()}/flat/32.png`;
}

function onFlagImageError(event) {
    event.target.src = '/dashboard/assets/images/flags/us_flag.jpg';
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

function openEdit(flag) {
    modalType.value = 'edit';
    selectedRecord.value = { ...flag };
    modalShow.value = true;
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchFlags(page);
}

function confirmDelete(id) {
    deleteConfirm.open({
        title: t('flags.delete_title'),
        message: t('flags.confirm_delete'),
        payload: { type: 'single', id },
    });
}

function confirmDeleteSelected() {
    deleteConfirm.open({
        title: t('flags.delete_selected_title'),
        message: t('flags.confirm_delete_selected'),
        payload: { type: 'multiple' },
    });
}

async function handleDeleteConfirm() {
    deleteConfirm.setLoading(true);

    try {
        if (deleteConfirm.state.payload?.type === 'multiple') {
            await deleteSelected();
        } else {
            await deleteFlag(deleteConfirm.state.payload.id);
        }
    } finally {
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
    }
}

function onSaved() {
    modalShow.value = false;
    fetchFlags(currentPage.value);
}

onMounted(() => {
    fetchFlags();
});
</script>

<style scoped>
.flags-toolbar-filters {
    flex-wrap: wrap;
}

.flags-toolbar-search {
    width: 210px;
    max-width: 210px;
    flex-shrink: 0;
}

.flag-img {
    display: block;
    object-fit: cover;
    border-radius: 2px;
    flex-shrink: 0;
}

.flags-search-clear {
    padding-inline: 0.5rem;
    line-height: 1;
}

.flags-filter-btn {
    border-width: 1px;
    border-style: solid;
    font-weight: 500;
    white-space: nowrap;
}

.flags-filter-btn--all {
    background-color: #845adf;
    border-color: #845adf;
    color: #fff;
}

.flags-filter-btn--all-idle {
    background-color: rgba(132, 90, 223, 0.12);
    border-color: rgba(132, 90, 223, 0.35);
    color: #845adf;
}

.flags-filter-btn--active {
    background-color: #26bf94;
    border-color: #26bf94;
    color: #fff;
}

.flags-filter-btn--active-idle {
    background-color: rgba(38, 191, 148, 0.12);
    border-color: rgba(38, 191, 148, 0.35);
    color: #26bf94;
}

.flags-filter-btn--inactive {
    background-color: #6c757d;
    border-color: #6c757d;
    color: #fff;
}

.flags-filter-btn--inactive-idle {
    background-color: #f3f6f8;
    border-color: #dee2e6;
    color: #6c757d;
}

@media (max-width: 767.98px) {
    .flags-toolbar-search {
        width: 100%;
        max-width: 220px;
    }
}
</style>
