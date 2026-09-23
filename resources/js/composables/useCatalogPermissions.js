import { computed } from 'vue';
import { usePermission } from './usePermission';

/**
 * Admin catalog CRUD permissions (group matches AdminPermissionSeeder group_name).
 *
 * @param {string} group e.g. flags, countries, admins
 */
export function useCatalogPermissions(group) {
    const { permissionNames } = usePermission();

    const canView = computed(() => permissionNames.value.includes(`${group}.view`));
    const canCreate = computed(() => permissionNames.value.includes(`${group}.create`));
    const canUpdate = computed(() => permissionNames.value.includes(`${group}.update`));
    const canDelete = computed(() => permissionNames.value.includes(`${group}.delete`));
    const canChangeStatus = computed(() => permissionNames.value.includes(`${group}.change-status`));
    const canMultipleDelete = computed(() => permissionNames.value.includes(`${group}.multiple-delete`));

    const showActionsColumn = computed(() => canUpdate.value || canDelete.value);

    return {
        canView,
        canCreate,
        canUpdate,
        canDelete,
        canChangeStatus,
        canMultipleDelete,
        showActionsColumn,
    };
}
