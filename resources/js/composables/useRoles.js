import crudStructure from './crudStructure';

export function useRoles() {
    const crud = crudStructure({
        confirmDeleteKey: 'roles.confirm_delete',
        searchDefaults: {
            searchInTranslations: false,
            columns: ['name'],
        },
    });

    crud.uri.value = '/api/admin/v1/roles';

    return {
        roles: crud.data,
        loading: crud.loading,
        pagination: crud.dataPaginate,
        selectedIds: crud.selectedIds,
        perPage: crud.paginate,
        currentPage: crud.pagePaginate,
        search: crud.searchText,
        fetchRoles: crud.getData,
        deleteRole: (id) => crud.deleteData(id, true),
        deleteSelected: () => crud.deleteData([...crud.selectedIds.value], true),
        toggleSelectAll: crud.toggleSelectAll,
        toggleSelect: crud.toggleSelect,
    };
}
