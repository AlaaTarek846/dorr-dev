import adminAxios from '../api/adminAxios';
import crudStructure, { statusFilterParams } from './crudStructure';

/**
 * Generic catalog list composable (flags, countries, languages, currencies).
 *
 * @example
 * const catalog = useCatalog({
 *   apiUri: '/api/admin/v1/countries',
 *   countsStore: useCountriesStore(),
 *   confirmDeleteKey: 'countries.confirm_delete',
 *   searchDefaults: { columns: ['code'], searchInTranslations: true, filterTranslationByLocale: false },
 *   dataKey: 'countries',
 * });
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

    async function fetchCounts() {
        if (! countsStore) {
            return;
        }

        const base = { paginate: 1, page: 1 };

        const results = await Promise.allSettled([
            fetchCount(base),
            fetchCount({ ...base, ...statusFilterParams('active') }),
            fetchCount({ ...base, ...statusFilterParams('inactive') }),
        ]);

        countsStore.setCounts({
            total: results[0].status === 'fulfilled' ? results[0].value : countsStore.total,
            active: results[1].status === 'fulfilled' ? results[1].value : countsStore.activeCount,
            inactive: results[2].status === 'fulfilled' ? results[2].value : countsStore.inactiveCount,
        });
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

    crud.uri.value = apiUri;

    const api = {
        loading: crud.loading,
        pagination: crud.dataPaginate,
        selectedIds: crud.selectedIds,
        perPage: crud.paginate,
        currentPage: crud.pagePaginate,
        search: crud.searchText,
        statusFilter: crud.statusFilter,
        fetchItems: crud.getData,
        fetchCounts,
        setStatusFilter: crud.setStatusFilter,
        deleteItem: (id) => crud.deleteData(id, true),
        deleteSelected: () => crud.deleteData([...crud.selectedIds.value], true),
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
