import { useCatalog } from './useCatalog';
import { useCountriesStore } from '../stores/countries';

export function useCountries() {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/countries',
        countsStore: useCountriesStore(),
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
        fetchCountries: catalog.fetchItems,
        fetchCounts: catalog.fetchCounts,
        setStatusFilter: catalog.setStatusFilter,
        deleteCountry: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}
