<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('providers.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('providers.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                        <div class="d-flex flex-wrap align-items-center gap-1 providers-toolbar-filters">
                            <div class="input-group input-group-sm providers-toolbar-search">
                                <span class="input-group-text bg-white">
                                    <i class="ri-search-line text-muted"></i>
                                </span>
                                <input
                                    v-model="search"
                                    type="search"
                                    class="form-control"
                                    :placeholder="t('providers.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border providers-search-clear"
                                    :title="t('providers.clear_search')"
                                    @click="clearSearch"
                                >
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <button
                                v-if="showAllFilter"
                                type="button"
                                class="btn btn-sm providers-filter-btn"
                                :class="statusFilter === 'all' ? 'providers-filter-btn--all' : 'providers-filter-btn--all-idle'"
                                @click="setStatusFilter('all')"
                            >
                                {{ t('providers.filter_all') }} ({{ counts.total }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm providers-filter-btn"
                                :class="statusFilter === 'active' ? 'providers-filter-btn--active' : 'providers-filter-btn--active-idle'"
                                @click="setStatusFilter('active')"
                            >
                                {{ t('providers.filter_active') }} ({{ counts.active }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm providers-filter-btn"
                                :class="statusFilter === 'inactive' ? 'providers-filter-btn--inactive' : 'providers-filter-btn--inactive-idle'"
                                @click="setStatusFilter('inactive')"
                            >
                                {{ t('providers.filter_inactive') }} ({{ counts.inactive }})
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm providers-filter-btn"
                                :class="statusFilter === 'blocked' ? 'providers-filter-btn--blocked' : 'providers-filter-btn--blocked-idle'"
                                @click="setStatusFilter('blocked')"
                            >
                                {{ t('providers.filter_blocked') }} ({{ counts.blocked }})
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
                                {{ t('providers.delete_count', { count: selectedCount }) }}
                            </button>

                            <button type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                <i class="ri-add-line me-1 align-middle"></i>
                                {{ t('providers.add_short') }}
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
                                                :disabled="loading || !providers.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col">{{ t('providers.name') }}</th>
                                        <th scope="col">{{ t('email') }}</th>
                                        <th scope="col">{{ t('providers.phone') }}</th>
                                        <th scope="col">{{ t('providers.gender') }}</th>
                                        <th scope="col">{{ t('providers.services') }}</th>
                                        <th scope="col">{{ t('providers.status') }}</th>
                                        <th scope="col">{{ t('providers.created_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('providers.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="9" />

                                    <tr v-else-if="!providers.length">
                                        <td colspan="9" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-user-search-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('providers.empty_title') }}</p>
                                                <p class="text-muted mb-3">{{ t('providers.empty') }}</p>
                                                <button type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                                                    <i class="ri-add-line me-1 align-middle"></i>
                                                    {{ t('providers.add') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr
                                            v-for="provider in providers"
                                            :key="provider.id"
                                            class="crm-contact"
                                        >
                                            <td class="ps-4">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    :checked="isSelected(provider.id)"
                                                    @change="onRowSelect(provider.id, $event.target.checked)"
                                                >
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="avatar avatar-sm avatar-rounded">
                                                        <img
                                                            v-if="provider.avatar_thumb || provider.avatar"
                                                            :src="provider.avatar_thumb || provider.avatar"
                                                            :alt="provider.name"
                                                            class="provider-avatar-img"
                                                        >
                                                        <span v-else class="avatar avatar-sm avatar-rounded bg-primary-transparent">
                                                            <i class="ri-user-line text-primary"></i>
                                                        </span>
                                                    </span>
                                                    <div>
                                                        <button
                                                            type="button"
                                                            class="btn btn-link p-0 text-start fw-semibold text-default"
                                                            @click="openEdit(provider)"
                                                        >
                                                            {{ provider.name }}
                                                        </button>
                                                        <span class="d-block text-muted fs-11">
                                                            #{{ provider.id }}
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>{{ provider.email }}</td>
                                            <td>
                                                <span v-if="provider.phone" class="providers-phone" dir="ltr">
                                                    {{ formatProviderPhone(provider) }}
                                                </span>
                                                <span v-else>-</span>
                                            </td>
                                            <td>{{ genderLabel(provider.gender) }}</td>
                                            <td>
                                                <div v-if="serviceLabels(provider).length" class="d-flex flex-wrap gap-1">
                                                    <span
                                                        v-for="(label, index) in serviceLabels(provider).slice(0, 2)"
                                                        :key="`${provider.id}-service-${index}`"
                                                        class="badge bg-primary-transparent"
                                                    >
                                                        {{ label }}
                                                    </span>
                                                    <span
                                                        v-if="serviceLabels(provider).length > 2"
                                                        class="badge bg-light text-muted"
                                                    >
                                                        +{{ serviceLabels(provider).length - 2 }}
                                                    </span>
                                                </div>
                                                <span v-else class="text-muted">-</span>
                                            </td>
                                            <td>
                                                <select
                                                    class="form-select form-select-sm w-auto providers-status-select"
                                                    :class="statusSelectClass(provider.status)"
                                                    :value="provider.status"
                                                    :disabled="isTogglingStatus(provider.id)"
                                                    @change="changeProviderStatus(provider, $event.target.value)"
                                                >
                                                    <option
                                                        v-for="(label, value) in statusOptions"
                                                        :key="value"
                                                        :value="value"
                                                    >
                                                        {{ label }}
                                                    </option>
                                                </select>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatDate(provider.created_at) }}</span>
                                                <span v-if="provider.updated_at" class="d-block text-muted fs-11">
                                                    {{ t('providers.updated') }}: {{ formatDate(provider.updated_at) }}
                                                </span>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div class="btn-list justify-content-end">
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-info-light btn-icon"
                                                        :title="t('providers.edit_title')"
                                                        @click="openEdit(provider)"
                                                    >
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-danger-light btn-icon"
                                                        :title="t('providers.confirm_delete')"
                                                        @click="confirmDelete(provider.id)"
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
                                <label class="text-muted fs-13 mb-0" for="providers-per-page">{{ t('providers.per_page') }}</label>
                                <select
                                    id="providers-per-page"
                                    v-model.number="perPage"
                                    class="form-select form-select-sm w-auto"
                                >
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="Providers pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('providers.previous') }}
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
                                            {{ t('providers.next') }}
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
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useProviders } from '../../../../../../composables/useProviders';
import { useProvidersStore } from '../../../../../../stores/providers';
import { displayTranslatedName, formatPhoneForDisplay } from '../../../../../../utils/catalog';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const providersStore = useProvidersStore();

const providersApi = useProviders();
const {
    providers,
    loading,
    pagination,
    selectedIds,
    currentPage,
    perPage,
    search,
    statusFilter,
} = storeToRefs(providersApi);
const {
    fetchProviders,
    setStatusFilter,
    deleteProvider,
    deleteSelected,
    changeProviderStatus,
    toggleSelectAll,
    toggleSelect,
    isTogglingStatus,
} = providersApi;

const counts = computed(() => ({
    total: providersStore.total ?? pagination.value?.total ?? 0,
    active: providersStore.activeCount ?? 0,
    inactive: providersStore.inactiveCount ?? 0,
    blocked: providersStore.blockedCount ?? 0,
}));

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);
const deleteConfirm = useConfirmDelete();

const selectedCount = computed(() => selectedIds.value.length);

const showAllFilter = computed(() => statusFilter.value !== 'all');

const statusOptions = computed(() => ({
    active: t('providers.filter_active'),
    inactive: t('providers.filter_inactive'),
    blocked: t('providers.filter_blocked'),
}));

const paginationArrowIcon = computed(() => (
    locale.value === 'ar'
        ? 'ri-arrow-left-s-line fw-semibold'
        : 'ri-arrow-right-s-line fw-semibold'
));

const allSelected = computed(() => {
    if (! providers.value.length) {
        return false;
    }

    return providers.value.every((provider) => selectedIds.value.includes(Number(provider.id)));
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

    return t('providers.showing_entries', {
        from: pagination.value.from ?? 0,
        to: pagination.value.to ?? 0,
        total: pagination.value.total ?? 0,
    });
});

function statusSelectClass(status) {
    if (status === 'active') {
        return 'providers-status-select--active';
    }

    if (status === 'blocked') {
        return 'providers-status-select--blocked';
    }

    return 'providers-status-select--inactive';
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

function formatProviderPhone(provider) {
    return formatPhoneForDisplay(provider?.phone, provider?.phone_code ?? provider?.country?.dial_code);
}

function serviceLabels(provider) {
    return (provider?.services ?? [])
        .map((service) => displayTranslatedName(service.category, locale.value))
        .filter(Boolean);
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

function openEdit(provider) {
    modalType.value = 'edit';
    selectedRecord.value = { ...provider };
    modalShow.value = true;
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchProviders(page);
}

function confirmDelete(id) {
    deleteConfirm.open({
        title: t('providers.delete_title'),
        message: t('providers.confirm_delete'),
        payload: { type: 'single', id },
    });
}

function confirmDeleteSelected() {
    deleteConfirm.open({
        title: t('providers.delete_selected_title'),
        message: t('providers.confirm_delete_selected'),
        payload: { type: 'multiple' },
    });
}

async function handleDeleteConfirm() {
    deleteConfirm.setLoading(true);

    try {
        if (deleteConfirm.state.payload?.type === 'multiple') {
            await deleteSelected();
        } else {
            await deleteProvider(deleteConfirm.state.payload.id);
        }
    } finally {
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
    }
}

function onSaved() {
    modalShow.value = false;
    fetchProviders(currentPage.value);
}

onMounted(() => {
    fetchProviders();
});
</script>

<style scoped>
.providers-toolbar-filters {
    flex-wrap: wrap;
}

.providers-toolbar-search {
    width: 210px;
    max-width: 210px;
    flex-shrink: 0;
}

.provider-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.providers-phone {
    display: inline-block;
    direction: ltr;
    unicode-bidi: isolate;
    white-space: nowrap;
}

.providers-search-clear {
    padding-inline: 0.5rem;
    line-height: 1;
}

.providers-filter-btn {
    border-width: 1px;
    border-style: solid;
    font-weight: 500;
    white-space: nowrap;
}

.providers-filter-btn--all {
    background-color: #845adf;
    border-color: #845adf;
    color: #fff;
}

.providers-filter-btn--all-idle {
    background-color: rgba(132, 90, 223, 0.12);
    border-color: rgba(132, 90, 223, 0.35);
    color: #845adf;
}

.providers-filter-btn--active {
    background-color: #26bf94;
    border-color: #26bf94;
    color: #fff;
}

.providers-filter-btn--active-idle {
    background-color: rgba(38, 191, 148, 0.12);
    border-color: rgba(38, 191, 148, 0.35);
    color: #26bf94;
}

.providers-filter-btn--inactive {
    background-color: #6c757d;
    border-color: #6c757d;
    color: #fff;
}

.providers-filter-btn--inactive-idle {
    background-color: #f3f6f8;
    border-color: #dee2e6;
    color: #6c757d;
}

.providers-filter-btn--blocked {
    background-color: #e6533c;
    border-color: #e6533c;
    color: #fff;
}

.providers-filter-btn--blocked-idle {
    background-color: rgba(230, 83, 60, 0.12);
    border-color: rgba(230, 83, 60, 0.35);
    color: #e6533c;
}

.providers-status-select {
    min-width: 7.5rem;
    font-weight: 500;
    border-width: 1px;
}

.providers-status-select--active {
    background-color: rgba(38, 191, 148, 0.12);
    border-color: rgba(38, 191, 148, 0.35);
    color: #26bf94;
}

.providers-status-select--inactive {
    background-color: #f3f6f8;
    border-color: #dee2e6;
    color: #6c757d;
}

.providers-status-select--blocked {
    background-color: rgba(230, 83, 60, 0.12);
    border-color: rgba(230, 83, 60, 0.35);
    color: #e6533c;
}

@media (max-width: 767.98px) {
    .providers-toolbar-search {
        width: 100%;
        max-width: 220px;
    }
}
</style>
