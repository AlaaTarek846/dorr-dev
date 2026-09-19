import { useCatalog } from './useCatalog';
import { useServiceCategoriesStore } from '../stores/serviceCategories';

export function useServiceCategories() {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/service-categories',
        countsStore: useServiceCategoriesStore(),
        lazyCounts: true,
        confirmDeleteKey: 'service_categories.confirm_delete',
        searchDefaults: {
            columns: [],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'categories',
    });

    return {
        categories: catalog.categories,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        fetchCategories: catalog.fetchItems,
        setStatusFilter: catalog.setStatusFilter,
        deleteCategory: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}