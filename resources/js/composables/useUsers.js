import { watch } from 'vue';
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

    async function fetchCount(params) {
        const { data } = await adminAxios.get('/api/admin/v1/users', { params });

        return data.pagination?.total ?? 0;
    }

    async function fetchCounts() {
        const base = { paginate: 1, page: 1 };

        const results = await Promise.allSettled([
            fetchCount(base),
            fetchCount({ ...base, ...userStatusFilterParams('active') }),
            fetchCount({ ...base, ...userStatusFilterParams('inactive') }),
            fetchCount({ ...base, ...userStatusFilterParams('blocked') }),
        ]);

        usersStore.setCounts({
            total: results[0].status === 'fulfilled' ? results[0].value : usersStore.total,
            active: results[1].status === 'fulfilled' ? results[1].value : usersStore.activeCount,
            inactive: results[2].status === 'fulfilled' ? results[2].value : usersStore.inactiveCount,
            blocked: results[3].status === 'fulfilled' ? results[3].value : usersStore.blockedCount,
        });
    }

    const crud = crudStructure({
        confirmDeleteKey: 'users.confirm_delete',
        searchDefaults: {
            searchInTranslations: false,
            columns: ['name', 'email', 'phone'],
        },
        fetchCounts,
        onAfterFetch(data, { statusFilter }) {
            if (statusFilter === 'all' && data.pagination?.total != null) {
                usersStore.setCounts({ total: data.pagination.total });
            }
        },
    });

    crud.uri.value = '/api/admin/v1/users';

    watch(crud.statusFilter, (value) => {
        if (value === 'all') {
            crud.filterColumns.value = [];
            return;
        }

        crud.filterColumns.value = userStatusFilterParams(value).filterColumns;
    }, { immediate: true });

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
            await fetchCounts();
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
        fetchCounts,
        setStatusFilter: crud.setStatusFilter,
        deleteUser: (id) => crud.deleteData(id, true),
        deleteSelected: () => crud.deleteData([...crud.selectedIds.value], true),
        changeUserStatus,
        toggleSelectAll: crud.toggleSelectAll,
        toggleSelect: crud.toggleSelect,
        isTogglingStatus: crud.isTogglingStatus,
    };
}
