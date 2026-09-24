import { useI18n } from 'vue-i18n';
import adminAxios from '../api/adminAxios';
import crudStructure from './crudStructure';
import useToast, { extractApiErrorMessage, extractApiMessage } from './useToast';
import { useEmployeesStore } from '../stores/employees';

function employeeStatusFilterParams(status) {
    if (status === 'all') {
        return {};
    }

    const value = status === 'active' ? 1 : 0;

    return {
        filterColumns: {
            columns: [{ column: 'status', opreator: '=', value }],
        },
    };
}

export function useEmployees() {
    const employeesStore = useEmployeesStore();
    const { t } = useI18n();
    const { showSuccess, showError } = useToast();

    const crud = crudStructure({
        confirmDeleteKey: 'employees.confirm_delete',
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
                employeesStore.setCounts({ total });
            } else if (statusFilter === 'active') {
                employeesStore.setCounts({ active: total });
            } else if (statusFilter === 'inactive') {
                employeesStore.setCounts({ inactive: total });
            }
        },
    });

    crud.uri.value = '/api/admin/v1/admins';

    crud.setStatusFilter = (value) => {
        crud.statusFilter.value = value;
        crud.filterColumns.value = value === 'all' ? [] : employeeStatusFilterParams(value).filterColumns;
        crud.getData(1);
    };

    async function changeEmployeeStatus(employee, status) {
        const nextStatus = status === true || status === 'true' || status === 1 || status === '1';

        if (! employee?.id || employee.status === nextStatus) {
            return;
        }

        if (crud.isTogglingStatus(employee.id)) {
            return;
        }

        const previousStatus = employee.status;
        employee.status = nextStatus;
        crud.togglingStatusIds.value = [...crud.togglingStatusIds.value, employee.id];

        try {
            const response = await adminAxios.patch(`${crud.uri.value}/${employee.id}/status`, {
                status: nextStatus,
            });
            showSuccess(extractApiMessage(response, t('toast.status_changed')));
            await crud.getData(crud.pagePaginate.value);
        } catch (error) {
            employee.status = previousStatus;
            showError(extractApiErrorMessage(error, t('toast.error')));
        } finally {
            crud.togglingStatusIds.value = crud.togglingStatusIds.value.filter((id) => id !== employee.id);
        }
    }

    return {
        employees: crud.data,
        loading: crud.loading,
        pagination: crud.dataPaginate,
        selectedIds: crud.selectedIds,
        perPage: crud.paginate,
        currentPage: crud.pagePaginate,
        search: crud.searchText,
        statusFilter: crud.statusFilter,
        fetchEmployees: crud.getData,
        setStatusFilter: crud.setStatusFilter,
        deleteEmployee: (id) => crud.deleteData(id, true),
        deleteSelected: () => crud.deleteData([...crud.selectedIds.value], true),
        changeEmployeeStatus,
        toggleSelectAll: crud.toggleSelectAll,
        toggleSelect: crud.toggleSelect,
        isTogglingStatus: crud.isTogglingStatus,
    };
}
