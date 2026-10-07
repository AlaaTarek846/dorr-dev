import { ref, watch } from 'vue';
import adminAxios from '../api/adminAxios';
import crudStructure from './crudStructure';

export function useReferralCodes() {
    const crud = crudStructure({
        searchDefaults: {
            searchInTranslations: false,
            columns: ['code'],
        },
    });

    crud.uri.value = '/api/admin/v1/referral-codes';

    const ownerTypeFilter = ref('all');
    const statusFilter = ref('all');

    function applyFilters() {
        const columns = [];

        if (ownerTypeFilter.value !== 'all') {
            columns.push({ column: 'referrable_type', opreator: '=', value: ownerTypeFilter.value });
        }

        if (statusFilter.value !== 'all') {
            columns.push({
                column: 'is_active',
                opreator: '=',
                value: statusFilter.value === 'active' ? 1 : 0,
            });
        }

        crud.filterColumns.value = columns.length ? { columns } : [];
        crud.getData(1);
    }

    watch([ownerTypeFilter, statusFilter], applyFilters);

    const selectedRecord = ref(null);
    const modalShow = ref(false);

    async function showRecord(id) {
        const response = await adminAxios.get(`${crud.uri.value}/${id}`);
        selectedRecord.value = response.data.data;
        modalShow.value = true;
    }

    return {
        codes: crud.data,
        loading: crud.loading,
        pagination: crud.dataPaginate,
        perPage: crud.paginate,
        currentPage: crud.pagePaginate,
        search: crud.searchText,
        ownerTypeFilter,
        statusFilter,
        fetchCodes: crud.getData,
        changeStatus: crud.changeStatus,
        selectedRecord,
        modalShow,
        showRecord,
        closeModal() {
            modalShow.value = false;
        },
    };
}
