import { useCatalog } from './useCatalog';
import { useCountriesStore } from '../stores/countries';

export function useCountries() {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/countries',
        countsStore: useCountriesStore(),
        lazyCounts: true,
        confirmDeleteKey: 'countries.confirm_delete',
        searchDefaults: {
            columns: ['code', 'dial_code'],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'countries',
    });

    return {
        countries: catalog.countries,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        isDeletedView: catalog.isDeletedView,
        fetchCountries: catalog.fetchItems,
        setStatusFilter: catalog.setStatusFilter,
        deleteCountry: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        restoreCountry: catalog.restoreItem,
        forceDeleteCountry: catalog.forceDeleteItem,
        forceDeleteSelected: catalog.forceDeleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}
