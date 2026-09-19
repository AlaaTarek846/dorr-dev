/**
 * Shared confirm + handler helpers for catalog trash UI (restore / force delete).
 */
export function useCatalogTrashActions({
    t,
    deleteConfirm,
    deleteSelected,
    deleteItem,
    restoreItem,
    forceDeleteItem,
    forceDeleteSelected,
    i18nPrefix,
}) {
    function confirmDelete(id) {
        deleteConfirm.open({
            title: t(`${i18nPrefix}.delete_title`),
            message: t(`${i18nPrefix}.confirm_delete`),
            payload: { type: 'delete-single', id },
        });
    }

    function confirmDeleteSelected() {
        deleteConfirm.open({
            title: t(`${i18nPrefix}.delete_selected_title`),
            message: t(`${i18nPrefix}.confirm_delete_selected`),
            payload: { type: 'delete-multiple' },
        });
    }

    function confirmForceDelete(id) {
        deleteConfirm.open({
            title: t('catalog.force_delete_title'),
            message: t('catalog.confirm_force_delete'),
            payload: { type: 'force-single', id },
        });
    }

    function confirmForceDeleteSelected() {
        deleteConfirm.open({
            title: t('catalog.force_delete_title'),
            message: t('catalog.confirm_force_delete_selected'),
            payload: { type: 'force-multiple' },
        });
    }

    function confirmRestore(id) {
        deleteConfirm.open({
            title: t('catalog.restore_title'),
            message: t('catalog.confirm_restore'),
            payload: { type: 'restore-single', id },
        });
    }

    async function handleDeleteConfirm() {
        deleteConfirm.setLoading(true);

        try {
            const payload = deleteConfirm.state.payload;

            if (payload?.type === 'delete-multiple') {
                await deleteSelected();
            } else if (payload?.type === 'force-multiple') {
                await forceDeleteSelected();
            } else if (payload?.type === 'force-single') {
                await forceDeleteItem(payload.id);
            } else if (payload?.type === 'restore-single') {
                await restoreItem(payload.id);
            } else {
                await deleteItem(payload.id);
            }
        } finally {
            deleteConfirm.setLoading(false);
            deleteConfirm.close();
        }
    }

    return {
        confirmDelete,
        confirmDeleteSelected,
        confirmForceDelete,
        confirmForceDeleteSelected,
        confirmRestore,
        handleDeleteConfirm,
    };
}
