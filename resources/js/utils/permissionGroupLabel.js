/**
 * @param {import('vue-i18n').ComposerTranslation} t
 */
export function permissionGroupLabel(t, groupName) {
    const key = String(groupName ?? '').trim();

    if (! key || key === 'ungrouped') {
        return t('roles.permissions_ungrouped');
    }

    const i18nKey = `roles.permission_groups.${key}`;
    const translated = t(i18nKey);

    return translated !== i18nKey ? translated : key;
}
