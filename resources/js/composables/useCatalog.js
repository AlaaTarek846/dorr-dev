import { useI18n } from 'vue-i18n';
import adminAxios from '../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from './useToast';
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
    lazyCounts = false,
    confirmDeleteKey = 'confirm.delete_title',
    searchDefaults = {
        columns: ['code'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
    },
    dataKey = 'items',
}) {
    const { t } = useI18n();
    const { showSuccess, showError } = useToast();

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
        fetchCounts: countsStore && ! lazyCounts ? fetchCounts : null,
        onAfterFetch(data, { statusFilter }) {
            if (! countsStore || data.pagination?.total == null) {
                return;
            }

            const total = data.pagination.total;

            if (! lazyCounts) {
                if (statusFilter === 'all') {
                    countsStore.setCounts({ total });
                }

                return;
            }

            if (statusFilter === 'all') {
                countsStore.setCounts({ total });
            } else if (statusFilter === 'active') {
                countsStore.setCounts({ active: total });
            } else if (statusFilter === 'inactive') {
                countsStore.setCounts({ inactive: total });
            }
        },
    });

    crud.uri.value = apiUri;

    if (lazyCounts) {
        crud.toggleStatus = async (row) => {
            if (! row?.id || crud.isTogglingStatus(row.id)) {
                return;
            }

            const previousStatus = row.status;
            row.status = ! previousStatus;
            crud.togglingStatusIds.value = [...crud.togglingStatusIds.value, row.id];

            try {
                const response = await adminAxios.patch(`${crud.uri.value}/${row.id}/status`, { status: row.status });
                showSuccess(extractApiMessage(response, t('toast.status_changed')));
                await crud.getData(crud.pagePaginate.value);
            } catch (error) {
                row.status = previousStatus;
                showError(extractApiErrorMessage(error, t('toast.error')));
            } finally {
                crud.togglingStatusIds.value = crud.togglingStatusIds.value.filter((id) => id !== row.id);
            }
        };
    }

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
