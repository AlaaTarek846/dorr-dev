import { useCatalog } from './useCatalog';
import { useAiSiteHostingPlansStore } from '../stores/aiSiteHostingPlans';

/** Admin site hosting plans list - the same catalog list as useCountries (search, status filters with counts, trash, bulk delete). */
export function useAiSiteHostingPlans() {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/ai-site-hosting-plans',
        countsStore: useAiSiteHostingPlansStore(),
        confirmDeleteKey: 'ai_site_hosting.confirm_delete',
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
