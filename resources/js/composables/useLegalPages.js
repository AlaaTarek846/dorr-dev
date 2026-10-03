import { ref } from 'vue';
import { useCatalog } from './useCatalog';
import { useLegalPagesStore } from '../stores/legalPages';

export function useLegalPages() {
    const typeFilter = ref('');

    const catalog = useCatalog({
        apiUri: '/api/admin/v1/legal-pages',
        countsStore: useLegalPagesStore(),
        lazyCounts: true,
        confirmDeleteKey: 'legal_pages.confirm_delete',
        searchDefaults: {
            columns: [],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'pages',
        getExtraListParams: () => (typeFilter.value ? { type: typeFilter.value } : {}),
    });

    function setTypeFilter(value) {
        typeFilter.value = value;
        catalog.fetchItems(1);
    }

    return {
        pages: catalog.pages,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        typeFilter,
        setTypeFilter,
        isDeletedView: catalog.isDeletedView,
        fetchPages: catalog.fetchItems,
        setStatusFilter: catalog.setStatusFilter,
        deletePage: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        restorePage: catalog.restoreItem,
        forceDeletePage: catalog.forceDeleteItem,
        forceDeleteSelected: catalog.forceDeleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}