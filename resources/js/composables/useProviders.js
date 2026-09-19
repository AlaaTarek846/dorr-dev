import { useI18n } from 'vue-i18n';
import adminAxios from '../api/adminAxios';
import crudStructure from './crudStructure';
import useToast, { extractApiErrorMessage, extractApiMessage } from './useToast';
import { useProvidersStore } from '../stores/providers';

function providerStatusFilterParams(status) {
    if (status === 'all') {
        return {};
    }

    return {
        filterColumns: {
            columns: [{ column: 'status', opreator: '=', value: status }],
        },
    };
}

export function useProviders() {
    const providersStore = useProvidersStore();
    const { t } = useI18n();
    const { showSuccess, showError } = useToast();

    const crud = crudStructure({
        confirmDeleteKey: 'providers.confirm_delete',
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
                providersStore.setCounts({ total });
            } else if (statusFilter === 'active') {
                providersStore.setCounts({ active: total });
            } else if (statusFilter === 'inactive') {
                providersStore.setCounts({ inactive: total });
            } else if (statusFilter === 'blocked') {
                providersStore.setCounts({ blocked: total });
            }
        },
    });

    crud.uri.value = '/api/admin/v1/providers';

    crud.setStatusFilter = (value) => {
        crud.statusFilter.value = value;
        crud.filterColumns.value = value === 'all' ? [] : providerStatusFilterParams(value).filterColumns;
        crud.getData(1);
    };

    async function changeProviderStatus(provider, status) {
        if (! provider?.id || provider.status === status) {
            return;
        }

        if (crud.isTogglingStatus(provider.id)) {
            return;
        }

        const previousStatus = provider.status;
        provider.status = status;
        crud.togglingStatusIds.value = [...crud.togglingStatusIds.value, provider.id];

        try {
            const response = await adminAxios.patch(`${crud.uri.value}/${provider.id}/status`, { status });
            showSuccess(extractApiMessage(response, t('toast.status_changed')));
            await crud.getData(crud.pagePaginate.value);
        } catch (error) {
            provider.status = previousStatus;
            showError(extractApiErrorMessage(error, t('toast.error')));
        } finally {
            crud.togglingStatusIds.value = crud.togglingStatusIds.value.filter((id) => id !== provider.id);
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
        changeProviderStatus,
        toggleSelectAll: crud.toggleSelectAll,
        toggleSelect: crud.toggleSelect,
        isTogglingStatus: crud.isTogglingStatus,
    };
}
