import { useCatalog } from './useCatalog';
import { useFaqsStore } from '../stores/faqs';

export function useFaqs() {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/faqs',
        countsStore: useFaqsStore(),
        lazyCounts: true,
        confirmDeleteKey: 'faqs.confirm_delete',
        searchDefaults: {
            columns: [],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'faqs',
    });

    return {
        faqs: catalog.faqs,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        isDeletedView: catalog.isDeletedView,
        fetchFaqs: catalog.fetchItems,
        setStatusFilter: catalog.setStatusFilter,
        deleteFaq: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        restoreFaq: catalog.restoreItem,
        forceDeleteFaq: catalog.forceDeleteItem,
        forceDeleteSelected: catalog.forceDeleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}
