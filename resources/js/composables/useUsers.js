import { useI18n } from 'vue-i18n';
import adminAxios from '../api/adminAxios';
import crudStructure from './crudStructure';
import useToast, { extractApiErrorMessage, extractApiMessage } from './useToast';
import { useUsersStore } from '../stores/users';

function userStatusFilterParams(status) {
    if (status === 'all') {
        return {};
    }

    return {
        filterColumns: {
            columns: [{ column: 'status', opreator: '=', value: status }],
        },
    };
}

export function useUsers() {
    const usersStore = useUsersStore();
    const { t } = useI18n();
    const { showSuccess, showError } = useToast();

    const crud = crudStructure({
        confirmDeleteKey: 'users.confirm_delete',
        searchDefaults: {
            searchInTranslations: false,
            columns: ['name', 'email', 'phone'],
        },
        onAfterFetch(data, { statusFilter }) {
            const total = data.pagination?.total;

            if (total == null) {
                return;
            }

            if (statusFilter === 'all') {
                usersStore.setCounts({ total });
            } else if (statusFilter === 'active') {
                usersStore.setCounts({ active: total });
            } else if (statusFilter === 'inactive') {
                usersStore.setCounts({ inactive: total });
            } else if (statusFilter === 'blocked') {
                usersStore.setCounts({ blocked: total });
            }
        },
    });

    crud.uri.value = '/api/admin/v1/users';

    crud.setStatusFilter = (value) => {
        crud.statusFilter.value = value;
        crud.filterColumns.value = value === 'all' ? [] : userStatusFilterParams(value).filterColumns;
        crud.getData(1);
    };

    async function changeUserStatus(user, status) {
        if (! user?.id || user.status === status) {
            return;
        }

        if (crud.isTogglingStatus(user.id)) {
            return;
        }

        const previousStatus = user.status;
        user.status = status;
        crud.togglingStatusIds.value = [...crud.togglingStatusIds.value, user.id];

        try {
            const response = await adminAxios.patch(`${crud.uri.value}/${user.id}/status`, { status });
            showSuccess(extractApiMessage(response, t('toast.status_changed')));
            await crud.getData(crud.pagePaginate.value);
        } catch (error) {
            user.status = previousStatus;
            showError(extractApiErrorMessage(error, t('toast.error')));
        } finally {
            crud.togglingStatusIds.value = crud.togglingStatusIds.value.filter((id) => id !== user.id);
        }
    }

    return {
        users: crud.data,
        loading: crud.loading,
        pagination: crud.dataPaginate,
        selectedIds: crud.selectedIds,
        perPage: crud.paginate,
        currentPage: crud.pagePaginate,
        search: crud.searchText,
        statusFilter: crud.statusFilter,
        fetchUsers: crud.getData,
        setStatusFilter: crud.setStatusFilter,
        deleteUser: (id) => crud.deleteData(id, true),
        deleteSelected: () => crud.deleteData([...crud.selectedIds.value], true),
        changeUserStatus,
        toggleSelectAll: crud.toggleSelectAll,
        toggleSelect: crud.toggleSelect,
        isTogglingStatus: crud.isTogglingStatus,
    };
}