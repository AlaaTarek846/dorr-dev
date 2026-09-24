import { useCatalog } from './useCatalog';
import { useDashboardThemesStore } from '../stores/dashboardThemes';

export function useDashboardThemes() {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/dashboard-themes',
        countsStore: useDashboardThemesStore(),
        lazyCounts: true,
        confirmDeleteKey: 'dashboard_themes.confirm_delete',
        searchDefaults: {
            columns: ['slug', 'path'],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'dashboardThemes',
    });

    return {
        dashboardThemes: catalog.dashboardThemes,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        isDeletedView: catalog.isDeletedView,
        fetchDashboardThemes: catalog.fetchItems,
        fetchCounts: catalog.fetchCounts,
        setStatusFilter: catalog.setStatusFilter,
        deleteDashboardTheme: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        restoreDashboardTheme: catalog.restoreItem,
        forceDeleteDashboardTheme: catalog.forceDeleteItem,
        forceDeleteSelected: catalog.forceDeleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}
