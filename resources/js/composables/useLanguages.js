import { useCatalog } from './useCatalog';
import { useLanguagesStore } from '../stores/languages';

export function useLanguages() {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/languages',
        countsStore: useLanguagesStore(),
        confirmDeleteKey: 'languages.confirm_delete',
        searchDefaults: {
            columns: ['code'],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'languages',
    });

    return {
        languages: catalog.languages,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        isDeletedView: catalog.isDeletedView,
        fetchLanguages: catalog.fetchItems,
        fetchCounts: catalog.fetchCounts,
        setStatusFilter: catalog.setStatusFilter,
        deleteLanguage: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        restoreLanguage: catalog.restoreItem,
        forceDeleteLanguage: catalog.forceDeleteItem,
        forceDeleteSelected: catalog.forceDeleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}
