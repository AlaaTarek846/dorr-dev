import { ref, watch } from 'vue';
import { useCatalog } from './useCatalog';
import { useServiceCategoriesStore } from '../stores/serviceCategories';

const AUDIENCE_FILTER_VALUES = ['admin', 'user', 'provider', 'driver'];

export function useServiceCategories() {
    const audienceFilter = ref([]);

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
        getExtraListParams: () => (
            audienceFilter.value.length
                ? { audiences: [...audienceFilter.value] }
                : {}
        ),
    });

    watch(
        audienceFilter,
        () => {
            catalog.fetchItems(1);
        },
        { deep: true },
    );

    function clearAudienceFilter() {
        if (audienceFilter.value.length) {
            audienceFilter.value = [];
        }
    }

    return {
        audienceFilter,
        audienceFilterValues: AUDIENCE_FILTER_VALUES,
        clearAudienceFilter,
        categories: catalog.categories,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        isDeletedView: catalog.isDeletedView,
        fetchCategories: catalog.fetchItems,
        setStatusFilter: catalog.setStatusFilter,
        deleteCategory: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        restoreCategory: catalog.restoreItem,
        forceDeleteCategory: catalog.forceDeleteItem,
        forceDeleteSelected: catalog.forceDeleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}