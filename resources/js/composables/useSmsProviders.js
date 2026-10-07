import { useI18n } from 'vue-i18n';
import adminAxios from '../api/adminAxios';
import crudStructure from './crudStructure';
import useToast, { extractApiErrorMessage, extractApiMessage } from './useToast';
import { useSmsProvidersStore } from '../stores/smsProviders';

/**
 * SMS providers use `is_active` as their status column (not `status`), so the
 * generic catalog status filter params do not apply here.
 */
function smsStatusFilterParams(status) {
    if (status === 'active') {
        return {
            filterColumns: {
                columns: [{ column: 'is_active', opreator: '=', value: 1 }],
            },
        };
    }

    if (status === 'inactive') {
        return {
            filterColumns: {
                columns: [{ column: 'is_active', opreator: '=', value: 0 }],
            },
        };
    }

    return {};
}

export function useSmsProviders() {
    const providersStore = useSmsProvidersStore();
    const { t } = useI18n();
    const { showSuccess, showError } = useToast();

    const crud = crudStructure({
        confirmDeleteKey: 'sms.providers.confirm_delete',
        searchDefaults: {
            searchInTranslations: false,
            columns: ['name', 'key'],
        },
        onAfterFetch(data, { statusFilter }) {
            const total = data.pagination?.total;

            if (total == null) {
                return;
            }

            if (statusFilter === 'all') {
                providersStore.setCounts({ total });
            } else if (statusFilter === 'active') {
                providersStore.setCounts({ active: total });
            } else if (statusFilter === 'inactive') {
                providersStore.setCounts({ inactive: total });
            }
        },
    });

    crud.uri.value = '/api/admin/v1/sms-providers';

    crud.setStatusFilter = (value) => {
        crud.statusFilter.value = value;
        crud.filterColumns.value = value === 'all' ? [] : smsStatusFilterParams(value).filterColumns;
        crud.getData(1);
    };

    async function toggleActive(provider) {
        if (! provider?.id || crud.isTogglingStatus(provider.id)) {
            return;
        }

        const previousStatus = provider.is_active;
        provider.is_active = ! previousStatus;
        crud.togglingStatusIds.value = [...crud.togglingStatusIds.value, provider.id];

        try {
            const response = await adminAxios.patch(`${crud.uri.value}/${provider.id}/status`);
            showSuccess(extractApiMessage(response, t('toast.status_changed')));
        } catch (error) {
            provider.is_active = previousStatus;
            showError(extractApiErrorMessage(error, t('toast.error')));
        } finally {
            crud.togglingStatusIds.value = crud.togglingStatusIds.value.filter((id) => id !== provider.id);
        }
    }

    async function testProvider(provider) {
        if (! provider?.id) {
            return;
        }

        try {
            const response = await adminAxios.post(`${crud.uri.value}/${provider.id}/test`);
            showSuccess(extractApiMessage(response, t('sms.providers.ready_toast')));
            await crud.getData(crud.pagePaginate.value);
        } catch (error) {
            showError(extractApiErrorMessage(error, t('toast.error')));
        }
    }

    return {
        providers: crud.data,
        loading: crud.loading,
        pagination: crud.dataPaginate,
        selectedIds: crud.selectedIds,
        perPage: crud.paginate,
        currentPage: crud.pagePaginate,
        search: crud.searchText,
        statusFilter: crud.statusFilter,
        fetchProviders: crud.getData,
        setStatusFilter: crud.setStatusFilter,
        deleteProvider: (id) => crud.deleteData(id, true),
        deleteSelected: () => crud.deleteData([...crud.selectedIds.value], true),
        toggleActive,
        testProvider,
        toggleSelectAll: crud.toggleSelectAll,
        toggleSelect: crud.toggleSelect,
        isTogglingStatus: crud.isTogglingStatus,
    };
}