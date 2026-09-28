import { useCatalog } from './useCatalog';
import { useMobileAppFontsStore } from '../stores/mobileAppFonts';

export function useMobileAppFonts() {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/mobile-app-fonts',
        countsStore: useMobileAppFontsStore(),
        lazyCounts: true,
        confirmDeleteKey: 'mobile_app_fonts.confirm_delete',
        searchDefaults: {
            columns: ['slug'],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'mobile_app_fonts',
    });

    return {
        mobileAppFonts: catalog.mobile_app_fonts,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        isDeletedView: catalog.isDeletedView,
        fetchMobileAppFonts: catalog.fetchItems,
        setStatusFilter: catalog.setStatusFilter,
        deleteMobileAppFont: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        restoreMobileAppFont: catalog.restoreItem,
        forceDeleteMobileAppFont: catalog.forceDeleteItem,
        forceDeleteSelected: catalog.forceDeleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}
