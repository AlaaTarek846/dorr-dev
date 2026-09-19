import { useCatalog } from './useCatalog';
import { useCurrenciesStore } from '../stores/currencies';

export function useCurrencies() {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/currencies',
        countsStore: useCurrenciesStore(),
        lazyCounts: true,
        confirmDeleteKey: 'currencies.confirm_delete',
        searchDefaults: {
            columns: ['code', 'symbol'],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'currencies',
    });

    return {
        currencies: catalog.currencies,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        fetchCurrencies: catalog.fetchItems,
        setStatusFilter: catalog.setStatusFilter,
        deleteCurrency: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}
