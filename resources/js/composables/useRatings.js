import { ref, watch } from 'vue';
import crudStructure from './crudStructure';

/**
 * Dashboard list of the ratings people left from the app: search in the comment, filter by type
 * (feedback = 1–3 stars, review = 4–5 stars) and by stars, read, delete one or many.
 */
export function useRatings() {
    const crud = crudStructure({
        confirmDeleteKey: 'ratings.confirm_delete',
        searchDefaults: {
            searchInTranslations: false,
            columns: ['comment'],
        },
    });

    crud.uri.value = '/api/admin/v1/ratings';

    const typeFilter = ref('all');
    const starsFilter = ref('all');

    function applyFilters() {
        const columns = [];

        if (typeFilter.value !== 'all') {
            columns.push({ column: 'type', opreator: '=', value: typeFilter.value });
        }

        if (starsFilter.value !== 'all') {
            // Ratings can be 4.25 or 4.5, so "4 stars" means 4 up to (not including) 5.
            const from = Number(starsFilter.value);
            columns.push({ column: 'stars', opreator: '>=', value: from });
            columns.push({ column: 'stars', opreator: '<', value: from + 1 });
        }

        crud.filterColumns.value = columns.length ? { columns } : [];
        crud.getData(1);
    }

    watch([typeFilter, starsFilter], applyFilters);

    return {
        ratings: crud.data,
        loading: crud.loading,
        pagination: crud.dataPaginate,
        selectedIds: crud.selectedIds,
        perPage: crud.paginate,
        currentPage: crud.pagePaginate,
        search: crud.searchText,
        typeFilter,
        starsFilter,
        fetchRatings: crud.getData,
        deleteRating: (id) => crud.deleteData(id, true),
        deleteSelected: () => crud.deleteData([...crud.selectedIds.value], true),
        toggleSelectAll: crud.toggleSelectAll,
        toggleSelect: crud.toggleSelect,
    };
}
