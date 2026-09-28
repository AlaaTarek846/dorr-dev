import { useI18n } from 'vue-i18n';
import adminAxios from '../api/adminAxios';
import crudStructure from './crudStructure';
import useToast, { extractApiErrorMessage, extractApiMessage } from './useToast';
import { useSmsAccountsStore } from '../stores/smsAccounts';

/**
 * SMS accounts use `is_active` as their status column (not `status`), so the
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

export function useSmsAccounts() {
    const accountsStore = useSmsAccountsStore();
    const { t } = useI18n();
    const { showSuccess, showError } = useToast();

    const crud = crudStructure({
        confirmDeleteKey: 'sms.accounts.confirm_delete',
        searchDefaults: {
            searchInTranslations: false,
            columns: ['name', 'sender'],
        },
        onAfterFetch(data, { statusFilter }) {
            const total = data.pagination?.total;

            if (total == null) {
                return;
            }

            if (statusFilter === 'all') {
                accountsStore.setCounts({ total });
            } else if (statusFilter === 'active') {
                accountsStore.setCounts({ active: total });
            } else if (statusFilter === 'inactive') {
                accountsStore.setCounts({ inactive: total });
            }
        },
    });

    crud.uri.value = '/api/admin/v1/sms-accounts';

    crud.setStatusFilter = (value) => {
        crud.statusFilter.value = value;
        crud.filterColumns.value = value === 'all' ? [] : smsStatusFilterParams(value).filterColumns;
        crud.getData(1);
    };

    async function toggleActive(account) {
        if (! account?.id || crud.isTogglingStatus(account.id)) {
            return;
        }

        const previousStatus = account.is_active;
        account.is_active = ! previousStatus;
        crud.togglingStatusIds.value = [...crud.togglingStatusIds.value, account.id];

        try {
            const response = await adminAxios.patch(`${crud.uri.value}/${account.id}/status`);
            showSuccess(extractApiMessage(response, t('toast.status_changed')));
        } catch (error) {
            account.is_active = previousStatus;
            showError(extractApiErrorMessage(error, t('toast.error')));
        } finally {
            crud.togglingStatusIds.value = crud.togglingStatusIds.value.filter((id) => id !== account.id);
        }
    }

    async function setDefault(account) {
        if (! account?.id || account.is_default) {
            return;
        }

        try {
            const response = await adminAxios.post(`${crud.uri.value}/${account.id}/set-default`);
            showSuccess(extractApiMessage(response, t('toast.success')));
            await crud.getData(crud.pagePaginate.value);
        } catch (error) {
            showError(extractApiErrorMessage(error, t('toast.error')));
        }
    }

    async function testConnection(account) {
        if (! account?.id) {
            return;
        }

        try {
            const response = await adminAxios.post(`${crud.uri.value}/${account.id}/test`);
            showSuccess(extractApiMessage(response, t('sms.accounts.connection_toast')));
            await crud.getData(crud.pagePaginate.value);
        } catch (error) {
            showError(extractApiErrorMessage(error, t('toast.error')));
            await crud.getData(crud.pagePaginate.value);
        }
    }

    async function fetchBalance(account) {
        if (! account?.id) {
            return;
        }

        try {
            const response = await adminAxios.get(`${crud.uri.value}/${account.id}/balance`);
            const data = response.data?.data ?? {};
            const balance = data.balance;

            showSuccess(extractApiMessage(
                response,
                balance != null
                    ? t('sms.accounts.balance_toast', {
                        balance,
                        currency: data.currency ?? '',
                    })
                    : t('sms.accounts.balance_retrieved'),
            ));
        } catch (error) {
            showError(extractApiErrorMessage(error, t('toast.error')));
        }
    }

    return {
        accounts: crud.data,
        loading: crud.loading,
        pagination: crud.dataPaginate,
        selectedIds: crud.selectedIds,
        perPage: crud.paginate,
        currentPage: crud.pagePaginate,
        search: crud.searchText,
        statusFilter: crud.statusFilter,
        fetchAccounts: crud.getData,
        setStatusFilter: crud.setStatusFilter,
        deleteAccount: (id) => crud.deleteData(id, true),
        deleteSelected: () => crud.deleteData([...crud.selectedIds.value], true),
        toggleActive,
        setDefault,
        testConnection,
        fetchBalance,
        toggleSelectAll: crud.toggleSelectAll,
        toggleSelect: crud.toggleSelect,
        isTogglingStatus: crud.isTogglingStatus,
    };
}