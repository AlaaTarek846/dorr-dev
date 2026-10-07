import { computed, ref } from 'vue';
import { statusFilterParams } from './crudStructure';

/**
 * "All / Active / Inactive" tabs with counts for a wallet list that has an on/off `status` (the same tabs as the
 * currencies page). Choosing a tab sets the `filterColumns` the list sends. The counts are not fetched here: the list
 * asks for them (`useWalletList(..., { statusCounts: true })`) and they arrive in the same response, so opening the
 * page is one request.
 *
 * @param {object} filters  the reactive `filters` of useWalletList (needs a `filterColumns` key)
 * @param {import('vue').Ref<{total:number,active:number,inactive:number}|null>} statusCounts  from useWalletList
 */
export default function useWalletStatusTabs(filters, statusCounts) {
    const statusFilter = ref('all');
    const counts = computed(() => statusCounts.value ?? { total: null, active: null, inactive: null });

    function setStatusFilter(value) {
        statusFilter.value = value;
        filters.filterColumns = value === 'all' ? '' : statusFilterParams(value).filterColumns;
    }

    return { statusFilter, counts, setStatusFilter };
}
