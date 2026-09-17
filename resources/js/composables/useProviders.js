import { watch } from 'vue';
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

    async function fetchCount(params) {
        const { data } = await adminAxios.get('/api/admin/v1/providers', { params });

        return data.pagination?.total ?? 0;
    }

    async function fetchCounts() {
        const base = { paginate: 1, page: 1 };

        const results = await Promise.allSettled([
            fetchCount(base),
            fetchCount({ ...base, ...providerStatusFilterParams('active') }),
            fetchCount({ ...base, ...providerStatusFilterParams('inactive') }),
            fetchCount({ ...base, ...providerStatusFilterParams('blocked') }),
        ]);

        providersStore.setCounts({
            total: results[0].status === 'fulfilled' ? results[0].value : providersStore.total,
            active: results[1].status === 'fulfilled' ? results[1].value : providersStore.activeCount,
            inactive: results[2].status === 'fulfilled' ? results[2].value : providersStore.inactiveCount,
            blocked: results[3].status === 'fulfilled' ? results[3].value : providersStore.blockedCount,
        });
    }

    const crud = crudStructure({
        confirmDeleteKey: 'providers.confirm_delete',
        searchDefaults: {
            searchInTranslations: false,
            columns: ['name', 'email', 'phone'],
        },
        fetchCounts,
        onAfterFetch(data, { statusFilter }) {
            if (statusFilter === 'all' && data.pagination?.total != null) {
                providersStore.setCounts({ total: data.pagination.total });
            }
        },
    });

    crud.uri.value = '/api/admin/v1/providers';

    watch(crud.statusFilter, (value) => {
        if (value === 'all') {
            crud.filterColumns.value = [];
            return;
        }

        crud.filterColumns.value = providerStatusFilterParams(value).filterColumns;
    }, { immediate: true });

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
            await fetchCounts();
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
        fetchCounts,
        setStatusFilter: crud.setStatusFilter,
        deleteProvider: (id) => crud.deleteData(id, true),
        deleteSelected: () => crud.deleteData([...crud.selectedIds.value], true),
        changeProviderStatus,
        toggleSelectAll: crud.toggleSelectAll,
        toggleSelect: crud.toggleSelect,
        isTogglingStatus: crud.isTogglingStatus,
    };
}
