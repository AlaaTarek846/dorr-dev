<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('ratings.title') }}
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
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ratings.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                        <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                            <div class="input-group input-group-sm catalog-toolbar-search">
                                <span class="input-group-text bg-white">
                                    <i class="ri-search-line text-muted"></i>
                                </span>
                                <input
                                    v-model="search"
                                    type="search"
                                    class="form-control"
                                    :placeholder="t('ratings.search')"
                                >
                                <button
                                    v-if="search"
                                    type="button"
                                    class="btn btn-light border catalog-search-clear"
                                    :title="t('ratings.clear_search')"
                                    @click="clearSearch"
                                >
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>

                            <Select
                                v-model="typeFilter"
                                filter
                                :filter-placeholder="t('search_placeholder')"
                                :options="typeOptions"
                                option-label="label"
                                option-value="value"
                                class="wallet-filter-select"
                            />
                            <Select
                                v-model="starsFilter"
                                filter
                                :filter-placeholder="t('search_placeholder')"
                                :options="starsOptions"
                                option-label="label"
                                option-value="value"
                                class="wallet-filter-select"
                            />
                        </div>

                        <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                            <button
                                v-if="canMultipleDelete && selectedCount"
                                type="button"
                                class="btn btn-danger btn-sm btn-wave"
                                @click="confirmDeleteSelected"
                            >
                                <i class="ri-delete-bin-line me-1 align-middle"></i>
                                {{ t('ratings.delete_count', { count: selectedCount }) }}
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
                                                :disabled="loading || !ratings.length"
                                                @change="onSelectAll($event.target.checked)"
                                            >
                                        </th>
                                        <th scope="col" :class="{ 'ps-4': !canMultipleDelete }">{{ t('ratings.user') }}</th>
                                        <th scope="col">{{ t('ratings.rating') }}</th>
                                        <th scope="col">{{ t('ratings.type') }}</th>
                                        <th scope="col">{{ t('ratings.comment') }}</th>
                                        <th scope="col">{{ t('ratings.target') }}</th>
                                        <th scope="col">{{ t('ratings.date') }}</th>
                                        <th v-if="showActionsColumn" scope="col" class="text-end pe-4">{{ t('ratings.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="tableColumnCount" />

                                    <tr v-else-if="!ratings.length">
                                        <td :colspan="tableColumnCount" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-star-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('ratings.empty_title') }}</p>
                                                <p class="text-muted mb-0">{{ t('ratings.empty') }}</p>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr v-for="rating in ratings" :key="rating.id" class="crm-contact">
                                            <td v-if="canMultipleDelete" class="ps-4">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    :checked="isSelected(rating.id)"
                                                    @change="onRowSelect(rating.id, $event.target.checked)"
                                                >
                                            </td>
                                            <td :class="{ 'ps-4': !canMultipleDelete }">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="avatar avatar-sm avatar-rounded bg-primary-transparent">
                                                        <i class="ri-user-line text-primary"></i>
                                                    </span>
                                                    <div>
                                                        <button
                                                            v-if="canView"
                                                            type="button"
                                                            class="btn btn-link p-0 text-start fw-semibold text-default"
                                                            @click="openView(rating)"
                                                        >
                                                            {{ authorName(rating) }}
                                                        </button>
                                                        <span v-else class="fw-semibold text-default">{{ authorName(rating) }}</span>
                                                        <span class="d-block text-muted fs-11">
                                                            #{{ rating.id }}
                                                            <span v-if="rating.author?.phone" class="ms-1 users-phone" dir="ltr">{{ rating.author.phone }}</span>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="d-flex align-items-center gap-2">
                                                    <RatingStars :value="Number(rating.stars)" />
                                                    <span class="fw-semibold fs-13" dir="ltr">{{ Number(rating.stars) }}</span>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge" :class="rating.type === 'review' ? 'bg-success-transparent' : 'bg-warning-transparent'">
                                                    {{ rating.type === 'review' ? t('ratings.type_review') : t('ratings.type_feedback') }}
                                                </span>
                                            </td>
                                            <td class="text-wrap rating-comment">
                                                <span v-if="rating.comment">{{ rating.comment }}</span>
                                                <span v-else class="text-muted">—</span>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary-transparent">{{ targetLabel(rating) }}</span>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ formatDate(rating.created_at) }}</span>
                                            </td>
                                            <td v-if="showActionsColumn" class="text-end pe-4">
                                                <div class="btn-list justify-content-end">
                                                    <button
                                                        v-if="canView"
                                                        type="button"
                                                        class="btn btn-sm btn-info-light btn-icon"
                                                        :title="t('ratings.view')"
                                                        @click="openView(rating)"
                                                    >
                                                        <i class="ri-eye-line"></i>
                                                    </button>
                                                    <button
                                                        v-if="canDelete"
                                                        type="button"
                                                        class="btn btn-sm btn-danger-light btn-icon"
                                                        :title="t('ratings.delete_title')"
                                                        @click="confirmDelete(rating.id)"
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
                                <label class="text-muted fs-13 mb-0" for="ratings-per-page">{{ t('ratings.per_page') }}</label>
                                <select
                                    id="ratings-per-page"
                                    v-model.number="perPage"
                                    class="form-select form-select-sm w-auto"
                                >
                                    <option :value="15">15</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>

                            <nav aria-label="Ratings pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                                            {{ t('ratings.previous') }}
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
                                            {{ t('ratings.next') }}
                                        </button>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ModalShow
            :show="modalShow"
            :record="selectedRecord"
            @close="modalShow = false"
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
import Select from 'primevue/select';
import { computed, onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import ConfirmDeleteModal from '../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import { useRatings } from '../../../../../../composables/useRatings';
import ModalShow from './ModalShow.vue';
import RatingStars from './RatingStars.vue';

const { t, locale } = useI18n();

const {
    canView,
    canDelete,
    canMultipleDelete,
} = useCatalogPermissions('ratings');

const showActionsColumn = computed(() => canView.value || canDelete.value);

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

const ratingsApi = useRatings();
const {
    ratings,
    loading,
    pagination,
    selectedIds,
    currentPage,
    perPage,
    search,
    typeFilter,
    starsFilter,
} = storeToRefs(ratingsApi);
const {
    fetchRatings,
    deleteRating,
    deleteSelected,
    toggleSelectAll,
    toggleSelect,
} = ratingsApi;

const modalShow = ref(false);
const selectedRecord = ref(null);
const deleteConfirm = useConfirmDelete();

const selectedCount = computed(() => selectedIds.value.length);

const typeOptions = computed(() => [
    { value: 'all', label: t('ratings.filter_all_types') },
    { value: 'feedback', label: t('ratings.type_feedback') },
    { value: 'review', label: t('ratings.type_review') },
]);

const starsOptions = computed(() => [
    { value: 'all', label: t('ratings.filter_all_stars') },
    ...[5, 4, 3, 2, 1].map((n) => ({ value: String(n), label: t('ratings.stars_count', { count: n }) })),
]);

const paginationArrowIcon = computed(() => (
    locale.value === 'ar'
        ? 'ri-arrow-left-s-line fw-semibold'
        : 'ri-arrow-right-s-line fw-semibold'
));

const allSelected = computed(() => {
    if (! ratings.value.length) {
        return false;
    }

    return ratings.value.every((rating) => selectedIds.value.includes(Number(rating.id)));
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

    return t('ratings.showing_entries', {
        from: pagination.value.from ?? 0,
        to: pagination.value.to ?? 0,
        total: pagination.value.total ?? 0,
    });
});

function authorName(rating) {
    return rating.author?.name || rating.author?.phone || (rating.author ? `#${rating.author.id}` : t('ratings.deleted_user'));
}

/** What was rated: the app itself, or the named entity. */
function targetLabel(rating) {
    if (! rating.rateable) {
        return t('ratings.target_app');
    }

    return rating.rateable.name || `${rating.rateable.type} #${rating.rateable.id}`;
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

function openView(rating) {
    selectedRecord.value = { ...rating };
    modalShow.value = true;
}

function changePage(page) {
    if (! pagination.value) {
        return;
    }

    if (page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchRatings(page);
}

function confirmDelete(id) {
    deleteConfirm.open({
        title: t('ratings.delete_title'),
        message: t('ratings.confirm_delete'),
        payload: { type: 'single', id },
    });
}

function confirmDeleteSelected() {
    deleteConfirm.open({
        title: t('ratings.delete_selected_title'),
        message: t('ratings.confirm_delete_selected'),
        payload: { type: 'multiple' },
    });
}

async function handleDeleteConfirm() {
    deleteConfirm.setLoading(true);

    try {
        if (deleteConfirm.state.payload?.type === 'multiple') {
            await deleteSelected();
        } else {
            await deleteRating(deleteConfirm.state.payload.id);
        }
    } finally {
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
    }
}

onMounted(() => {
    fetchRatings();
});
</script>

<style scoped>
.rating-comment {
    max-width: 320px;
}

.users-phone {
    display: inline-block;
    direction: ltr;
    unicode-bidi: isolate;
    white-space: nowrap;
}
</style>
