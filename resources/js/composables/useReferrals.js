import { ref, watch } from 'vue';
import adminAxios from '../api/adminAxios';
import crudStructure from './crudStructure';

const LIST_URI = '/api/admin/v1/referrals';

export function useReferrals() {
    const referrerTypeFilter = ref([]);
    const referredTypeFilter = ref([]);
    const statusFilter = ref('all');
    const counts = ref({
        total: 0,
        registered: 0,
        completed: 0,
        cancelled: 0,
    });
    const selectedRecord = ref(null);
    const modalShow = ref(false);

    const crud = crudStructure({
        searchDefaults: {
            searchInTranslations: false,
            columns: [],
            searchInRelations: [
                { relation: 'referralCode', columns: ['code'] },
            ],
        },
        fetchCounts,
    });

    crud.uri.value = LIST_URI;

    function typeColumns(column, values) {
        const types = (values ?? []).filter(Boolean);

        if (! types.length) {
            return [];
        }

        return [{
            column,
            searchType: 'whereIn',
            value: types,
        }];
    }

    function currentTypeColumns() {
        return [
            ...typeColumns('referrer_type', referrerTypeFilter.value),
            ...typeColumns('referred_type', referredTypeFilter.value),
        ];
    }

    function statusColumns() {
        if (statusFilter.value === 'all') {
            return [];
        }

        return [{
            column: 'status',
            opreator: '=',
            value: statusFilter.value,
        }];
    }

    function applyFilters() {
        const columns = [...currentTypeColumns(), ...statusColumns()];
        crud.filterColumns.value = columns.length ? { columns } : [];
        crud.getData(1);
    }

    async function countWith(extraColumns = []) {
        const columns = [...currentTypeColumns(), ...extraColumns];
        const params = { paginate: 1, page: 1 };

        if (columns.length) {
            params.filterColumns = { columns };
        }

        const { data } = await adminAxios.get(LIST_URI, { params });

        return data.pagination?.total ?? 0;
    }

    async function fetchCounts() {
        const [total, registered, completed, cancelled] = await Promise.all([
            countWith([]),
            countWith([{ column: 'status', opreator: '=', value: 'registered' }]),
            countWith([{ column: 'status', opreator: '=', value: 'completed' }]),
            countWith([{ column: 'status', opreator: '=', value: 'cancelled' }]),
        ]);

        counts.value = { total, registered, completed, cancelled };
    }

    function setStatusFilter(value) {
        statusFilter.value = value;
    }

    async function showRecord(id) {
        const response = await adminAxios.get(`${LIST_URI}/${id}`);
        selectedRecord.value = response.data.data;
        modalShow.value = true;
    }

    function closeModal() {
        modalShow.value = false;
    }

    watch([referrerTypeFilter, referredTypeFilter, statusFilter], applyFilters, { deep: true });

    return {
        referrals: crud.data,
        loading: crud.loading,
        pagination: crud.dataPaginate,
        perPage: crud.paginate,
        currentPage: crud.pagePaginate,
        search: crud.searchText,
        referrerTypeFilter,
        referredTypeFilter,
        statusFilter,
        counts,
        fetchReferrals: crud.getData,
        setStatusFilter,
        selectedRecord,
        modalShow,
        showRecord,
        closeModal,
    };
}
