import { ref, watch } from 'vue';
import adminAxios from '../api/adminAxios';
import crudStructure from './crudStructure';

export function useReferrals() {
    const crud = crudStructure({
        searchDefaults: {
            searchInTranslations: false,
            columns: [],
            searchInRelations: [
                { relation: 'referralCode', columns: ['code'] },
            ],
        },
    });

    crud.uri.value = '/api/admin/v1/referrals';

    const statusFilter = ref('all');
    const referrerTypeFilter = ref('all');
    const referredTypeFilter = ref('all');

    function applyFilters() {
        const columns = [];

        if (statusFilter.value !== 'all') {
            columns.push({ column: 'status', opreator: '=', value: statusFilter.value });
        }

        if (referrerTypeFilter.value !== 'all') {
            columns.push({ column: 'referrer_type', opreator: '=', value: referrerTypeFilter.value });
        }

        if (referredTypeFilter.value !== 'all') {
            columns.push({ column: 'referred_type', opreator: '=', value: referredTypeFilter.value });
        }

        crud.filterColumns.value = columns.length ? { columns } : [];
        crud.getData(1);
    }

    watch([statusFilter, referrerTypeFilter, referredTypeFilter], applyFilters);

    const selectedRecord = ref(null);
    const modalShow = ref(false);

    async function showRecord(id) {
        const response = await adminAxios.get(`${crud.uri.value}/${id}`);
        selectedRecord.value = response.data.data;
        modalShow.value = true;
    }

    function closeModal() {
        modalShow.value = false;
    }

    return {
        referrals: crud.data,
        loading: crud.loading,
        pagination: crud.dataPaginate,
        perPage: crud.paginate,
        currentPage: crud.pagePaginate,
        search: crud.searchText,
        statusFilter,
        referrerTypeFilter,
        referredTypeFilter,
        fetchReferrals: crud.getData,
        selectedRecord,
        modalShow,
        showRecord,
        closeModal,
    };
}
