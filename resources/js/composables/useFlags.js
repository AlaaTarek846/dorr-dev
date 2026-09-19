import { useCatalog } from './useCatalog';
import { useFlagsStore } from '../stores/flags';

export function useFlags() {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/flags',
        countsStore: useFlagsStore(),
        lazyCounts: true,
        confirmDeleteKey: 'flags.confirm_delete',
        searchDefaults: {
            columns: ['code'],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'flags',
    });

    return {
        flags: catalog.flags,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        isDeletedView: catalog.isDeletedView,
        fetchFlags: catalog.fetchItems,
        setStatusFilter: catalog.setStatusFilter,
        deleteFlag: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        restoreFlag: catalog.restoreItem,
        forceDeleteFlag: catalog.forceDeleteItem,
        forceDeleteSelected: catalog.forceDeleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}
