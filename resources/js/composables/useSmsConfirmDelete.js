/**
 * Shared confirm + handler helpers for SMS admin pages (provider / account delete).
 */
export function useSmsConfirmDelete({
    t,
    deleteConfirm,
    deleteSelected,
    deleteItem,
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

    async function handleDeleteConfirm() {
        deleteConfirm.setLoading(true);

        try {
            const payload = deleteConfirm.state.payload;

            if (payload?.type === 'delete-multiple') {
                await deleteSelected();
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
        handleDeleteConfirm,
    };
}