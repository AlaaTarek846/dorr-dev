import { useCatalog } from './useCatalog';
import { useAiSiteOffersStore } from '../stores/aiSiteOffers';

/**
 * Admin website-builder offers / edit packs list - the same catalog list as useCountries (search, status filters with
 * counts, trash, bulk delete). `getKind()` = 'site' | 'edit_pack': the list and its counts only show that screen's kind.
 */
export function useAiSiteOffers({ getKind }) {
    const catalog = useCatalog({
        apiUri: '/api/admin/v1/ai-site-offers',
        countsStore: useAiSiteOffersStore(),
        confirmDeleteKey: 'ai_site_offers.confirm_delete',
        searchDefaults: {
            columns: ['code'],
            searchInTranslations: true,
            filterTranslationByLocale: false,
        },
        dataKey: 'offers',
        getExtraListParams: () => ({ kind: getKind() }),
    });

    return {
        offers: catalog.offers,
        loading: catalog.loading,
        pagination: catalog.pagination,
        selectedIds: catalog.selectedIds,
        perPage: catalog.perPage,
        currentPage: catalog.currentPage,
        search: catalog.search,
        statusFilter: catalog.statusFilter,
        isDeletedView: catalog.isDeletedView,
        fetchOffers: catalog.fetchItems,
        setStatusFilter: catalog.setStatusFilter,
        deleteOffer: catalog.deleteItem,
        deleteSelected: catalog.deleteSelected,
        restoreOffer: catalog.restoreItem,
        forceDeleteOffer: catalog.forceDeleteItem,
        forceDeleteSelected: catalog.forceDeleteSelected,
        toggleStatus: catalog.toggleStatus,
        toggleSelectAll: catalog.toggleSelectAll,
        toggleSelect: catalog.toggleSelect,
        togglingStatusIds: catalog.togglingStatusIds,
        isTogglingStatus: catalog.isTogglingStatus,
    };
}
