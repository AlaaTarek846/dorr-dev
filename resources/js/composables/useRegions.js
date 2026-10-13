import { ref, watch } from 'vue';
import { useCatalog } from './useCatalog';
import { useRegionsStore } from '../stores/regions';

export function useRegions() {
    // The list can be narrowed to one country.
    const countryFilter = ref(null);

    const catalog = useCatalog({
        apiUri: '/api/admin/v1/regions',
        countsStore: useRegionsStore(),
        lazyCounts: true,
        confirmDeleteKey: 'regions.confirm_delete',
        searchDefaults: {
            columns: [],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'regions',
        getExtraListParams: () => (countryFilter.value ? { country_id: countryFilter.value } : {}),
    });

    watch(countryFilter, () => catalog.fetchItems(1));

    return {
        regions: catalog.regions,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        isDeletedView: catalog.isDeletedView,
        countryFilter,
        fetchRegions: catalog.fetchItems,
        setStatusFilter: catalog.setStatusFilter,
        deleteRegion: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        restoreRegion: catalog.restoreItem,
        forceDeleteRegion: catalog.forceDeleteItem,
        forceDeleteSelected: catalog.forceDeleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}
