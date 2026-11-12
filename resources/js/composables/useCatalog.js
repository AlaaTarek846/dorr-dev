import { useI18n } from 'vue-i18n';
import adminAxios from '../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from './useToast';
import crudStructure, { statusFilterParams, trashedFilterParams } from './crudStructure';
import { computed, watch } from 'vue';


/**
 * Generic catalog list composable (flags, countries, languages, currencies).
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
    getExtraListParams = null,
}) {
    const { t } = useI18n();
    const { showSuccess, showError } = useToast();

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

        // The counts follow the same extra list params as the list (e.g. ?kind= on the offers / edit packs screens).
        const extra = typeof getExtraListParams === 'function' ? (getExtraListParams() ?? {}) : {};
        const base = { paginate: 1, page: 1, ...extra };

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

    /**
     * Lazy mode: active / inactive / all come from the list the admin is looking at, but the Trash filter is only
     * shown when its count is known (> 0) - and the admin never opens Trash to learn it. So the trashed count alone is
     * asked for after every list load (and after delete / restore / force delete, which reload the list).
     */
    async function fetchDeletedCount() {
        if (! countsStore) {
            return;
        }

        // On the Trash view the list total is the trashed count (set in onAfterFetch) - no extra request.
        if (crudRef?.statusFilter.value !== 'deleted') {
            const extra = typeof getExtraListParams === 'function' ? (getExtraListParams() ?? {}) : {};

            try {
                countsStore.setCounts({ deleted: await fetchCount({ paginate: 1, page: 1, ...extra, ...trashedFilterParams() }) });
            } catch {
                // Keep the last known count; the list itself already loaded.
            }
        }

        if ((countsStore.deletedCount ?? 0) === 0 && crudRef?.statusFilter.value === 'deleted') {
            crudRef.setStatusFilter('all');
        }
    }

    const crud = crudStructure({
        confirmDeleteKey,
        searchDefaults,
        statusFilterEnabled: true,
        optimisticStatus: true,
        fetchCounts: countsStore ? (lazyCounts ? fetchDeletedCount : fetchCounts) : null,
        getExtraListParams,
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
            } else if (statusFilter === 'deleted') {
                countsStore.setCounts({ deleted: total });
            }
        },
    });

    crudRef = crud;
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
