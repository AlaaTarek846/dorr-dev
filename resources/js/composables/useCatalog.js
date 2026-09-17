import { computed, watch } from 'vue';
import adminAxios from '../api/adminAxios';
import crudStructure, { statusFilterParams, trashedFilterParams } from './crudStructure';

/**
 * Generic catalog list composable (flags, countries, languages, currencies).
 */
export function useCatalog({
    apiUri,
    countsStore = null,
    confirmDeleteKey = 'confirm.delete_title',
    searchDefaults = {
        columns: ['code'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
    },
    dataKey = 'items',
}) {
    async function fetchCount(params) {
        const { data } = await adminAxios.get(apiUri, { params });

        return data.pagination?.total ?? 0;
    }

    /** @type {ReturnType<typeof crudStructure>|null} */
    let crudRef = null;

    async function fetchCounts() {
        if (! countsStore) {
            return;
        }

        const base = { paginate: 1, page: 1 };

        const results = await Promise.allSettled([
            fetchCount(base),
            fetchCount({ ...base, ...statusFilterParams('active') }),
            fetchCount({ ...base, ...statusFilterParams('inactive') }),
            fetchCount({ ...base, ...trashedFilterParams() }),
        ]);

        countsStore.setCounts({
            total: results[0].status === 'fulfilled' ? results[0].value : countsStore.total,
            active: results[1].status === 'fulfilled' ? results[1].value : countsStore.activeCount,
            inactive: results[2].status === 'fulfilled' ? results[2].value : countsStore.inactiveCount,
            deleted: results[3].status === 'fulfilled' ? results[3].value : countsStore.deletedCount,
        });

        if ((countsStore.deletedCount ?? 0) === 0 && crudRef?.statusFilter.value === 'deleted') {
            crudRef.setStatusFilter('all');
        }
    }

    const crud = crudStructure({
        confirmDeleteKey,
        searchDefaults,
        statusFilterEnabled: true,
        optimisticStatus: true,
        fetchCounts: countsStore ? fetchCounts : null,
        onAfterFetch(data, { statusFilter }) {
            if (countsStore && statusFilter === 'all' && data.pagination?.total != null) {
                countsStore.setCounts({ total: data.pagination.total });
            }
        },
    });

    crudRef = crud;
    crud.uri.value = apiUri;

    const isDeletedView = computed(() => crud.isDeletedView.value);

    watch(() => crud.statusFilter.value, () => {
        crud.selectedIds.value = [];
    });

    const api = {
        loading: crud.loading,
        pagination: crud.dataPaginate,
        selectedIds: crud.selectedIds,
        perPage: crud.paginate,
        currentPage: crud.pagePaginate,
        search: crud.searchText,
        statusFilter: crud.statusFilter,
        isDeletedView,
        fetchItems: crud.getData,
        fetchCounts,
        setStatusFilter: crud.setStatusFilter,
        deleteItem: (id) => crud.deleteData(id, true),
        deleteSelected: () => crud.deleteData([...crud.selectedIds.value], true),
        restoreItem: (id) => crud.restoreRecord(id),
        forceDeleteItem: (id) => crud.forceDeleteRecord(id, true),
        forceDeleteSelected: () => crud.forceDeleteSelected(true),
        toggleStatus: crud.toggleStatus,
        toggleSelectAll: crud.toggleSelectAll,
        toggleSelect: crud.toggleSelect,
        togglingStatusIds: crud.togglingStatusIds,
        isTogglingStatus: crud.isTogglingStatus,
        crud,
    };

    api[dataKey] = crud.data;

    return api;
}
