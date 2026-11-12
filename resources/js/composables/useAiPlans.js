import { useCatalog } from './useCatalog';
import { useAiPlansStore } from '../stores/aiPlans';

/** Admin AI plans list - the same catalog list as useCountries (search, status filters, trash, bulk delete). */
export function useAiPlans() {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/ai-plans',
        countsStore: useAiPlansStore(),
        // Full counts (not lazy): the Trash filter only shows when its count is known.
        lazyCounts: false,
        confirmDeleteKey: 'ai_plans.confirm_delete',
        searchDefaults: {
            columns: ['code'],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'plans',
    });

    return {
        plans: catalog.plans,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        isDeletedView: catalog.isDeletedView,
        fetchPlans: catalog.fetchItems,
        setStatusFilter: catalog.setStatusFilter,
        deletePlan: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        restorePlan: catalog.restoreItem,
        forceDeletePlan: catalog.forceDeleteItem,
        forceDeleteSelected: catalog.forceDeleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}
