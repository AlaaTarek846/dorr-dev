import { useCatalog } from './useCatalog';
import { usePrivacyPoliciesStore } from '../stores/privacyPolicies';

export function usePrivacyPolicies() {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/privacy-policies',
        countsStore: usePrivacyPoliciesStore(),
        lazyCounts: true,
        confirmDeleteKey: 'privacy_policies.confirm_delete',
        searchDefaults: {
            columns: [],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'policies',
    });

    return {
        policies: catalog.policies,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        isDeletedView: catalog.isDeletedView,
        fetchPolicies: catalog.fetchItems,
        setStatusFilter: catalog.setStatusFilter,
        deletePolicy: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        restorePolicy: catalog.restoreItem,
        forceDeletePolicy: catalog.forceDeleteItem,
        forceDeleteSelected: catalog.forceDeleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}
