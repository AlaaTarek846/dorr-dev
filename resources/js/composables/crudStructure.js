import { computed, ref, watch } from 'vue';

export function trashedFilterParams() {
    return { trashed: 1 };
}
import { useI18n } from 'vue-i18n';
import adminAxios from '../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage, resolveBulkDeleteFeedback } from './useToast';
import { useLocaleStore } from '../stores/locale';
import {
    filterRecordsForStatusView,
    resolveActiveSelectedIds,
    resolveTrashedSelectedIds,
} from '../utils/catalog';

export function statusFilterParams(statusFilter) {
    if (statusFilter === 'active') {
        return {
            filterColumns: {
                columns: [{ column: 'status', opreator: '=', value: 1 }],
            },
        };
    }

    if (statusFilter === 'inactive') {
        return {
            filterColumns: {
                columns: [{ column: 'status', opreator: '=', value: 0 }],
            },
        };
    }

    return {};
}

/**
 * Shared CRUD composable for admin listing pages.
 *
 * Usage:
 * const crud = crudStructure({ searchDefaults: { columns: ['code'] } });
 * crud.uri.value = '/api/admin/v1/flags';
 * crud.getData();
 */
export default function crudStructure(options = {}) {
    const {
        deleteMultiplePath = 'delete-multiple',
        statusMethod = 'patch',
        statusPath = (id) => `${id}/status`,
        confirmDeleteKey = 'flags.confirm_delete',
        searchDefaults = {
            searchInTranslations: false,
            columns: [],
            filterTranslationByLocale: false,
        },
        statusFilterEnabled = false,
        optimisticStatus = false,
        fetchCounts = null,
        onAfterFetch = null,
    } = options;

    const errors = ref({});
    const loading = ref(false);
    const { t } = useI18n();
    const { showSuccess, showError, showWarning } = useToast();
    const localeStore = useLocaleStore();

    const type = ref('create');
    const dataRow = ref(null);
    const actionUrl = ref('');
    const actionData = ref('');
    const modalShow = ref(false);
    const reasonShow = ref(false);
    const printShow = ref(false);
    const showData = ref(false);
    const allCheckRows = ref(false);
    const step = ref(0);
    const data = ref([]);
    const selectedIds = ref([]);
    const dataPaginate = ref({});
    const filter = ref('');
    const paginate = ref(15);
    const uri = ref('');
    const pagePaginate = ref(1);
    const statusFilter = ref('all');
    const togglingStatusIds = ref([]);
    const searchText = ref('');

    const search = ref({
        searchKey: '',
        searchInTranslations: searchDefaults.searchInTranslations ?? false,
        columns: searchDefaults.columns ?? [],
        filterTranslationByLocale: searchDefaults.filterTranslationByLocale ?? false,
    });

    const filterColumns = ref([]);
    const debounce = ref(null);

    const permission = computed(() => []);
    const isDeletedView = computed(() => statusFilter.value === 'deleted');

    watch(searchText, (value) => {
        search.value.searchKey = value;
    });

    function buildListParams(page = pagePaginate.value) {
        const params = {
            paginate: paginate.value,
            page,
        };

        if (statusFilter.value === 'deleted') {
            Object.assign(params, trashedFilterParams());
        } else if (statusFilterEnabled) {
            Object.assign(params, statusFilterParams(statusFilter.value));
        } else if (filterColumns.value?.length || filterColumns.value?.columns?.length) {
            params.filterColumns = filterColumns.value;
        }

        if (search.value.searchKey?.trim()) {
            params.search = JSON.stringify({
                ...searchDefaults,
                searchKey: search.value.searchKey.trim(),
            });
        }

        return params;
    }

    async function getData(page = 1) {
        pagePaginate.value = page;
        loading.value = true;
        data.value = [];

        try {
            const response = await adminAxios.get(uri.value, { params: buildListParams(page) });

            dataPaginate.value = response.data.pagination ?? {};
            data.value = filterRecordsForStatusView(
                response.data.data ?? [],
                statusFilter.value,
            );
            selectedIds.value = [];
            step.value = 1;

            if (typeof onAfterFetch === 'function') {
                await onAfterFetch(response.data, { statusFilter: statusFilter.value });
            }

            if (typeof fetchCounts === 'function') {
                await fetchCounts();
            }
        } catch (error) {
            showError(extractApiErrorMessage(error, t('toast.error')));
        } finally {
            loading.value = false;
        }
    }

    function setStatusFilter(value) {
        statusFilter.value = value;
        getData(1);
    }

    async function changeStatus(id, status, row = null) {
        if (optimisticStatus && row) {
            await toggleStatus(row);
            return;
        }

        loading.value = true;

        const url = `${uri.value}/${statusPath(id)}`;
        const request = statusMethod === 'patch'
            ? adminAxios.patch(url, { status })
            : adminAxios.post(`${uri.value}/change-status/${id}/${status ? 1 : 0}`);

        try {
            const response = await request;
            showSuccess(extractApiMessage(response, t('toast.status_changed')));
            await getData(pagePaginate.value);
        } catch (error) {
            showError(extractApiErrorMessage(error, t('toast.error')));
        } finally {
            loading.value = false;
        }
    }

    async function toggleStatus(row) {
        if (togglingStatusIds.value.includes(row.id)) {
            return;
        }

        const previousStatus = row.status;
        row.status = ! row.status;
        togglingStatusIds.value = [...togglingStatusIds.value, row.id];

        try {
            const response = await adminAxios.patch(`${uri.value}/${statusPath(row.id)}`, {
                status: row.status,
            });
            showSuccess(extractApiMessage(response, t('toast.status_changed')));

            if (typeof fetchCounts === 'function') {
                await fetchCounts();
            }
        } catch (error) {
            row.status = previousStatus;
            showError(extractApiErrorMessage(error, t('toast.error')));
        } finally {
            togglingStatusIds.value = togglingStatusIds.value.filter((id) => id !== row.id);
        }
    }

    function isTogglingStatus(id) {
        return togglingStatusIds.value.includes(id);
    }

    function confirmAction(message) {
        return window.confirm(message);
    }

    async function restoreRecord(id) {
        loading.value = true;

        try {
            const response = await adminAxios.post(`${uri.value}/${id}/restore`);
            showSuccess(extractApiMessage(response, t('catalog.restored')));
            await getData(pagePaginate.value);
        } catch (error) {
            showError(extractApiErrorMessage(error, t('toast.error')));
        } finally {
            loading.value = false;
        }
    }

    async function forceDeleteRecord(id, skipConfirm = false) {
        const record = data.value.find((row) => Number(row.id) === Number(id));

        if (! record || ! resolveTrashedSelectedIds([record], [id]).length) {
            showError(t('catalog.bulk_force_delete_requires_trash'));
            await getData(pagePaginate.value);

            return;
        }

        if (! skipConfirm && ! confirmAction(t('catalog.confirm_force_delete'))) {
            return;
        }

        loading.value = true;

        try {
            const response = await adminAxios.delete(`${uri.value}/${id}/force`);
            showSuccess(extractApiMessage(response, t('catalog.force_deleted')));
            await getData(pagePaginate.value);
        } catch (error) {
            showError(extractApiErrorMessage(error, t('toast.error')));

            if (error?.response?.status === 409) {
                await getData(pagePaginate.value);
            }
        } finally {
            loading.value = false;
        }
    }

    async function forceDeleteSelected(skipConfirm = false) {
        const ids = resolveTrashedSelectedIds(data.value, selectedIds.value);

        if (! ids.length) {
            showError(t('catalog.bulk_force_delete_requires_trash'));

            return;
        }

        if (! skipConfirm && ! confirmAction(t('catalog.confirm_force_delete_selected'))) {
            return;
        }

        loading.value = true;

        let deleted = 0;
        let lastError = '';

        try {
            for (const id of ids) {
                try {
                    await adminAxios.delete(`${uri.value}/${id}/force`);
                    deleted += 1;
                } catch (error) {
                    lastError = extractApiErrorMessage(error, t('toast.error'));
                }
            }

            if (deleted === ids.length) {
                showSuccess(t('catalog.force_deleted_selected', { count: deleted }));
            } else if (deleted > 0) {
                showWarning(t('catalog.force_deleted_partial', { deleted, skipped: ids.length - deleted }));
            } else {
                showError(lastError || t('toast.error'));
            }

            await getData(pagePaginate.value);
        } finally {
            loading.value = false;
        }
    }

    async function deleteData(id, skipConfirm = false) {
        if (! skipConfirm && ! confirmAction(t(confirmDeleteKey))) {
            return;
        }

        if (Array.isArray(id)) {
            const ids = isDeletedView.value
                ? resolveTrashedSelectedIds(data.value, id)
                : resolveActiveSelectedIds(data.value, id);

            if (! ids.length) {
                showError(t(isDeletedView.value
                    ? 'catalog.bulk_force_delete_requires_trash'
                    : 'catalog.bulk_soft_delete_requires_active'));

                return;
            }

            try {
                const response = await adminAxios.post(`${uri.value}/${deleteMultiplePath}`, { ids });
                const feedback = resolveBulkDeleteFeedback(response, t('toast.deleted'));

                if (feedback.type === 'warning') {
                    showWarning(feedback.message);
                } else {
                    showSuccess(feedback.message);
                }

                await getData(pagePaginate.value);
            } catch (error) {
                const response = error?.response;

                if (response?.status === 409 && response?.data?.data?.skipped !== undefined) {
                    const feedback = resolveBulkDeleteFeedback(response, t('toast.error'));
                    showError(feedback.message);
                    await getData(pagePaginate.value);

                    return;
                }

                showError(extractApiErrorMessage(error, t('toast.error')));
            }

            return;
        }

        try {
            const response = await adminAxios.delete(`${uri.value}/${id}`);
            showSuccess(extractApiMessage(response, t('toast.deleted')));
            await getData(pagePaginate.value);
        } catch (error) {
            showError(extractApiErrorMessage(error, t('toast.error')));
        }
    }

    function toggleSelectAll(checked) {
        selectedIds.value = checked ? data.value.map((row) => Number(row.id)) : [];
        allCheckRows.value = checked;
    }

    function toggleSelect(id, checked) {
        const normalizedId = Number(id);

        if (checked) {
            if (! selectedIds.value.includes(normalizedId)) {
                selectedIds.value = [...selectedIds.value, normalizedId];
            }

            return;
        }

        selectedIds.value = selectedIds.value.filter((item) => item !== normalizedId);
        allCheckRows.value = false;
    }

    function deleteMedia(id, onSuccess) {
        if (! confirmAction(t(confirmDeleteKey))) {
            return;
        }

        loading.value = true;

        adminAxios.delete(`dashboard/medias/${id}`)
            .then((response) => {
                showSuccess(extractApiMessage(response, t('toast.deleted')));

                if (typeof onSuccess === 'function') {
                    onSuccess();
                }
            })
            .catch((error) => {
                showError(extractApiErrorMessage(error, t('toast.error')));
            })
            .finally(() => {
                loading.value = false;
            });
    }

    function exportExcel() {
        showError('Export is not configured yet.');
    }

    function showModelPrint(row) {
        dataRow.value = row;
        printShow.value = true;
    }

    function showDataModel(row) {
        dataRow.value = row;
        showData.value = true;
    }

    function showEditMode(row) {
        dataRow.value = row;
        type.value = 'edit';
        modalShow.value = true;
    }

    function showModelCreate() {
        dataRow.value = null;
        type.value = 'create';
        modalShow.value = true;
    }

    function showCopyModel(row) {
        dataRow.value = row;
        type.value = 'copy';
        showData.value = true;
    }

    function showModelReason(row, reasonType, url = '', dataAction = '') {
        actionData.value = dataAction;
        actionUrl.value = url;
        dataRow.value = row;
        type.value = reasonType;
        reasonShow.value = true;
    }

    function truncateString(str, maxLength) {
        if (! str || str.length <= maxLength) {
            return str ?? '';
        }

        return `${str.slice(0, maxLength)}...`;
    }

    function allCheckRowsFun() {
        toggleSelectAll(allCheckRows.value);
    }

    function addCheckTableAll(id) {
        toggleSelect(id, ! selectedIds.value.includes(Number(id)));
    }

    function dblclickRow(item) {
        showEditMode(item);
    }

    watch(() => localeStore.locale, () => {
        if (step.value === 1) {
            getData(pagePaginate.value);
        }
    });

    watch(searchText, () => {
        if (step.value !== 1) {
            return;
        }

        clearTimeout(debounce.value);
        debounce.value = setTimeout(() => getData(1), 400);
    });

    watch(paginate, () => {
        if (step.value !== 1) {
            return;
        }

        clearTimeout(debounce.value);
        debounce.value = setTimeout(() => getData(1), 400);
    });

    return {
        errors,
        loading,
        permission,
        type,
        modalShow,
        reasonShow,
        data,
        dataPaginate,
        search,
        searchText,
        filterColumns,
        dataRow,
        uri,
        t,
        filter,
        getData,
        showEditMode,
        showModelCreate,
        deleteData,
        deleteMedia,
        showModelReason,
        truncateString,
        showModelPrint,
        printShow,
        showCopyModel,
        showDataModel,
        showData,
        actionData,
        pagePaginate,
        actionUrl,
        allCheckRows,
        selectedIds,
        allCheckRowsFun,
        addCheckTableAll,
        toggleSelectAll,
        toggleSelect,
        dblclickRow,
        exportExcel,
        paginate,
        changeStatus,
        toggleStatus,
        isTogglingStatus,
        togglingStatusIds,
        statusFilter,
        setStatusFilter,
        isDeletedView,
        restoreRecord,
        forceDeleteRecord,
        forceDeleteSelected,
        showSuccess,
        showError,
        showWarning,
    };
}
