import { ref, watch } from 'vue';
import adminAxios from '../api/adminAxios';
import crudStructure from './crudStructure';
import { extractApiErrorMessage, extractApiMessage } from './useToast';

const LIST_URI = '/api/admin/v1/referral-codes';

export function useReferralCodes() {
    const ownerTypeFilter = ref([]);
    const statusFilter = ref('all');
    const counts = ref({ total: 0, active: 0, inactive: 0 });
    const selectedRecord = ref(null);
    const modalShow = ref(false);

    const crud = crudStructure({
        searchDefaults: {
            searchInTranslations: false,
            columns: ['code'],
        },
        fetchCounts,
    });

    crud.uri.value = LIST_URI;

    function ownerTypeColumns() {
        const types = (ownerTypeFilter.value ?? []).filter(Boolean);

        if (! types.length) {
            return [];
        }

        return [{
            column: 'referrable_type',
            searchType: 'whereIn',
            value: types,
        }];
    }

    function statusColumns() {
        if (statusFilter.value === 'all') {
            return [];
        }

        return [{
            column: 'is_active',
            opreator: '=',
            value: statusFilter.value === 'active' ? 1 : 0,
        }];
    }

    function applyFilters() {
        const columns = [...ownerTypeColumns(), ...statusColumns()];
        crud.filterColumns.value = columns.length ? { columns } : [];
        crud.getData(1);
    }

    async function countWith(extraColumns = []) {
        const columns = [...ownerTypeColumns(), ...extraColumns];
        const params = { paginate: 1, page: 1 };

        if (columns.length) {
            params.filterColumns = { columns };
        }

        const { data } = await adminAxios.get(LIST_URI, { params });

        return data.pagination?.total ?? 0;
    }

    async function fetchCounts() {
        const [total, active, inactive] = await Promise.all([
            countWith([]),
            countWith([{ column: 'is_active', opreator: '=', value: 1 }]),
            countWith([{ column: 'is_active', opreator: '=', value: 0 }]),
        ]);

        counts.value = { total, active, inactive };
    }

    function setStatusFilter(value) {
        statusFilter.value = value;
    }

    function setOwnerTypeFilter(value) {
        ownerTypeFilter.value = value;
    }

    async function toggleStatus(row) {
        if (crud.isTogglingStatus(row.id)) {
            return;
        }

        const previous = row.is_active;
        row.is_active = ! row.is_active;
        crud.togglingStatusIds.value = [...crud.togglingStatusIds.value, row.id];

        try {
            const response = await adminAxios.patch(`${LIST_URI}/${row.id}/status`, {
                status: row.is_active,
            });
            crud.showSuccess(extractApiMessage(response, crud.t('toast.status_changed')));
            await fetchCounts();
        } catch (error) {
            row.is_active = previous;
            crud.showError(extractApiErrorMessage(error, crud.t('toast.error')));
        } finally {
            crud.togglingStatusIds.value = crud.togglingStatusIds.value.filter((id) => id !== row.id);
        }
    }

    async function showRecord(id) {
        const response = await adminAxios.get(`${LIST_URI}/${id}`);
        selectedRecord.value = response.data.data;
        modalShow.value = true;
    }

    watch([ownerTypeFilter, statusFilter], applyFilters, { deep: true });

    return {
        codes: crud.data,
        loading: crud.loading,
        pagination: crud.dataPaginate,
        perPage: crud.paginate,
        currentPage: crud.pagePaginate,
        search: crud.searchText,
        ownerTypeFilter,
        statusFilter,
        counts,
        fetchCodes: crud.getData,
        setStatusFilter,
        setOwnerTypeFilter,
        toggleStatus,
        isTogglingStatus: crud.isTogglingStatus,
        selectedRecord,
        modalShow,
        showRecord,
        closeModal() {
            modalShow.value = false;
        },
    };
}
