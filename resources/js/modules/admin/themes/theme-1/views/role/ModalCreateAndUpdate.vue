<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog role-modal-dialog">
            <div class="modal-content role-modal-content">
                <div class="modal-header role-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">{{ modalTitle }}</h6>
                        <button type="button" class="btn-close role-modal-close" aria-label="Close" @click="close"></button>
                    </div>
                </div>
                <form class="role-modal-form" @submit.prevent="submit">
                    <div class="modal-body role-modal-body px-4 pb-2">
                        <div class="mb-3">
                            <label for="role-name" class="form-label">
                                {{ t('roles.name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="ri-shield-user-line"></i>
                                </span>
                                <input
                                    id="role-name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="nameInputClass"
                                    :placeholder="t('roles.name_placeholder')"
                                    :disabled="isProtectedRole"
                                    maxlength="200"
                                    @input="onFieldInput('name')"
                                >
                                <FormFieldFeedback v-bind="nameFeedback" />
                            </div>
                            <div v-if="nameMessage" class="invalid-feedback d-block">
                                {{ nameMessage }}
                            </div>
                        </div>

                        <div class="mb-0">
                            <label class="form-label">{{ t('roles.permissions') }}</label>

                            <div v-if="!serviceTabs.length" class="text-muted fs-13 py-3">
                                {{ t('roles.empty_permissions') }}
                            </div>

                            <template v-else>
                                <div class="role-permissions-toolbar-strip role-permissions-toolbar-global mb-2">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-primary-light btn-wave role-permission-bulk-btn"
                                        @click="setAllPermissions(true)"
                                    >
                                        <i class="ri-checkbox-multiple-line" aria-hidden="true"></i>
                                        <span>{{ t('roles.select_all_services') }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-light btn-wave role-permission-bulk-btn"
                                        @click="setAllPermissions(false)"
                                    >
                                        <i class="ri-close-circle-line" aria-hidden="true"></i>
                                        <span>{{ t('roles.clear_all_services') }}</span>
                                    </button>
                                </div>

                                <ul class="nav nav-tabs nav-tabs-header mb-0 role-permission-tabs" role="tablist">
                                    <li v-for="tab in serviceTabs" :key="tab.id" class="nav-item" role="presentation">
                                        <button
                                            type="button"
                                            class="nav-link"
                                            :class="{ active: activeServiceTab === tab.id }"
                                            role="tab"
                                            @click="activeServiceTab = tab.id"
                                        >
                                            {{ tab.label }}
                                            <span
                                                class="badge ms-1"
                                                :class="selectedCountForTab(tab) > 0 ? 'bg-primary-transparent' : 'bg-light text-muted'"
                                            >{{ selectedCountForTab(tab) }}</span>
                                        </button>
                                    </li>
                                </ul>

                                <div class="border border-top-0 rounded-bottom role-permission-panel">
                                    <div
                                        v-for="tab in serviceTabs"
                                        v-show="activeServiceTab === tab.id"
                                        :key="`panel-${tab.id}`"
                                        class="p-3"
                                    >
                                        <div class="role-permissions-toolbar-strip role-permission-service-toolbar mb-3">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary-light btn-wave role-permission-bulk-btn"
                                                @click="setTabPermissions(tab, true)"
                                            >
                                                <i class="ri-checkbox-multiple-line" aria-hidden="true"></i>
                                                <span>{{ t('roles.select_all_service') }}</span>
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-light btn-wave role-permission-bulk-btn"
                                                @click="setTabPermissions(tab, false)"
                                            >
                                                <i class="ri-close-circle-line" aria-hidden="true"></i>
                                                <span>{{ t('roles.clear_service') }}</span>
                                            </button>
                                        </div>

                                        <div class="row g-3 role-permission-groups-row">
                                            <div
                                                v-for="group in tab.groups"
                                                :key="`${tab.id}-${group.name}`"
                                                class="col-xl-3 col-lg-4 col-md-6"
                                            >
                                                <div class="role-permission-group h-100">
                                                    <h6 class="role-permission-group__title">{{ groupNameLabel(group.name) }}</h6>
                                                    <div class="role-permission-group__actions">
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-primary-light role-permission-bulk-btn role-permission-bulk-btn--compact"
                                                            @click="setGroupPermissions(group, true)"
                                                        >
                                                            <i class="ri-check-double-line" aria-hidden="true"></i>
                                                            <span>{{ t('roles.select_all_group') }}</span>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-light role-permission-bulk-btn role-permission-bulk-btn--compact"
                                                            @click="setGroupPermissions(group, false)"
                                                        >
                                                            <i class="ri-close-line" aria-hidden="true"></i>
                                                            <span>{{ t('roles.clear_group') }}</span>
                                                        </button>
                                                    </div>
                                                    <div class="role-permission-list">
                                                        <label
                                                            v-for="permission in group.permissions"
                                                            :key="permission.name"
                                                            class="form-check role-permission-check"
                                                        >
                                                            <input
                                                                class="form-check-input"
                                                                type="checkbox"
                                                                :checked="isPermissionSelected(permission.name)"
                                                                @change="togglePermission(permission.name, $event.target.checked)"
                                                            >
                                                            <span class="form-check-label">
                                                                {{ permissionActionLabel(permission.name) }}
                                                            </span>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <div v-if="serverErrors.permission_names?.[0]" class="invalid-feedback d-block">
                                {{ serverErrors.permission_names[0] }}
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer role-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('cancel') }}</button>
                        <button type="submit" class="btn btn-primary" :disabled="submitting">
                            {{ submitting ? t('saving') : t('save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import FormFieldFeedback from '../../../../../../components/ui/FormFieldFeedback.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { setupCatalogModalWatcher } from '../../../../../../utils/catalog';
import { permissionGroupLabel } from '../../../../../../utils/permissionGroupLabel';

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { stringFieldRules, applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const serviceTabs = ref([]);
const activeServiceTab = ref(null);
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');
const isProtectedRole = computed(() => isEdit.value && props.record?.name === 'super-admin');

const form = reactive({
    name: '',
    permission_names: [],
});

const rules = computed(() => ({
    name: stringFieldRules('roles.name', 200, 2),
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

function buildFieldState(fieldKey) {
    const feedback = computed(() => fieldFeedback(
        v$.value[fieldKey],
        serverErrors[fieldKey]?.[0],
        form[fieldKey],
    ));

    const inputClass = computed(() => ({
        'is-invalid': feedback.value.show && feedback.value.invalid,
        'is-valid': feedback.value.show && feedback.value.valid,
    }));

    const message = computed(() => {
        if (! feedback.value.invalid) {
            return null;
        }

        return v$.value[fieldKey]?.$errors[0]?.$message || serverErrors[fieldKey]?.[0] || null;
    });

    return { feedback, inputClass, message };
}

const nameState = buildFieldState('name');
const nameFeedback = nameState.feedback;
const nameInputClass = nameState.inputClass;
const nameMessage = nameState.message;

function clearServerError(field) {
    delete serverErrors[field];
}

function onFieldInput(field) {
    clearServerError(field);
    v$.value[field]?.$touch();
}

const modalTitle = computed(() => (isEdit.value ? t('roles.edit_title') : t('roles.create_title')));

function serviceTabLabel(permission) {
    const category = permission.service_category;

    if (category?.name) {
        return category.name;
    }

    if (category?.module_name) {
        return category.module_name;
    }

    return t('roles.permissions_general');
}

function buildServiceTabs(items) {
    const tabs = new Map();

    for (const permission of items) {
        if (! permission?.name) {
            continue;
        }

        const serviceKey = permission.service_category_id ?? 'none';

        if (! tabs.has(serviceKey)) {
            tabs.set(serviceKey, {
                id: String(serviceKey),
                serviceCategoryId: permission.service_category_id ?? null,
                label: serviceTabLabel(permission),
                groups: new Map(),
            });
        }

        const tab = tabs.get(serviceKey);

        if (permission.service_category?.name) {
            tab.label = permission.service_category.name;
        }

        const groupName = permission.group_name?.trim() || 'ungrouped';

        if (! tab.groups.has(groupName)) {
            tab.groups.set(groupName, []);
        }

        tab.groups.get(groupName).push({
            name: permission.name,
        });
    }

    const actionOrder = [
        'view',
        'create',
        'update',
        'delete',
        'change-status',
        'multiple-delete',
    ];

    function permissionSortKey(name) {
        const action = name.includes('.') ? name.split('.').slice(1).join('.') : name;
        const index = actionOrder.indexOf(action);

        return index === -1 ? 99 : index;
    }

    return Array.from(tabs.values())
        .map((tab) => {
            const groups = Array.from(tab.groups.entries())
                .map(([name, permissions]) => ({
                    name,
                    permissions: permissions.sort(
                        (a, b) => permissionSortKey(a.name) - permissionSortKey(b.name)
                            || a.name.localeCompare(b.name),
                    ),
                }))
                .sort((a, b) => a.name.localeCompare(b.name));

            const totalCount = groups.reduce((sum, group) => sum + group.permissions.length, 0);

            return {
                id: tab.id,
                serviceCategoryId: tab.serviceCategoryId,
                label: tab.label,
                groups,
                totalCount,
            };
        })
        .filter((tab) => tab.totalCount > 0)
        .sort((a, b) => a.label.localeCompare(b.label));
}

async function loadPermissionOptions() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/permissions', { params: { paginate: 0 } });
        const items = data.data ?? [];
        serviceTabs.value = buildServiceTabs(items);

        if (! serviceTabs.value.some((tab) => tab.id === activeServiceTab.value)) {
            activeServiceTab.value = serviceTabs.value[0]?.id ?? null;
        }
    } catch {
        serviceTabs.value = [];
        activeServiceTab.value = null;
    }
}

function groupNameLabel(groupName) {
    return permissionGroupLabel(t, groupName);
}

function permissionActionLabel(permissionName) {
    const action = permissionName.includes('.')
        ? permissionName.split('.').slice(1).join('.')
        : permissionName;

    const key = `roles.permission_actions.${action}`;
    const translated = t(key);

    return translated !== key ? translated : action;
}

function isPermissionSelected(name) {
    return form.permission_names.includes(name);
}

function togglePermission(name, checked) {
    if (checked) {
        if (! form.permission_names.includes(name)) {
            form.permission_names.push(name);
        }

        return;
    }

    form.permission_names = form.permission_names.filter((item) => item !== name);
}

function permissionNamesFromTab(tab) {
    return tab.groups.flatMap((group) => group.permissions.map((permission) => permission.name));
}

function selectedCountForTab(tab) {
    const selected = new Set(form.permission_names);

    return permissionNamesFromTab(tab).filter((name) => selected.has(name)).length;
}

function applyPermissionSelection(names, selected) {
    if (selected) {
        form.permission_names = [...new Set([...form.permission_names, ...names])];

        return;
    }

    const remove = new Set(names);
    form.permission_names = form.permission_names.filter((name) => ! remove.has(name));
}

function setGroupPermissions(group, selected) {
    applyPermissionSelection(group.permissions.map((p) => p.name), selected);
}

function setTabPermissions(tab, selected) {
    applyPermissionSelection(permissionNamesFromTab(tab), selected);
}

function setAllPermissions(selected) {
    const names = serviceTabs.value.flatMap((tab) => permissionNamesFromTab(tab));
    applyPermissionSelection(names, selected);
}

function fillForm(record) {
    form.name = record?.name ?? '';
    form.permission_names = [...(record?.permission_names ?? [])];
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function resetForm() {
    fillForm(null);
}

function openModal() {
    modalInstance ??= new window.bootstrap.Modal(modalElement.value, { focus: false });
    modalInstance.show();
}

function closeModal() {
    modalInstance?.hide();
}

function close() {
    closeModal();
    emit('close');
}

async function submit() {
    v$.value.$touch();
    if (v$.value.$invalid) {
        showWarning(t('toast.validation_error'));
        return;
    }

    submitting.value = true;
    applyApiErrors(serverErrors, {});

    try {
        const payload = {
            name: form.name.trim(),
            permission_names: form.permission_names,
        };
        const response = isEdit.value && props.record?.id
            ? await adminAxios.put(`/api/admin/v1/roles/${props.record.id}`, payload)
            : await adminAxios.post('/api/admin/v1/roles', payload);

        showSuccess(extractApiMessage(response, isEdit.value ? t('toast.updated') : t('toast.created')));
        closeModal();
        emit('saved');
    } catch (error) {
        if (error.response?.status === 422) {
            applyApiErrors(serverErrors, error.response.data.errors ?? {});
            showWarning(t('toast.validation_error'));
        } else {
            showError(extractApiErrorMessage(error, t('toast.error')));
        }
    } finally {
        submitting.value = false;
    }
}

setupCatalogModalWatcher({
    props,
    fillForm,
    resetForm,
    openModal,
    closeModal,
    resourceUri: '/api/admin/v1/roles',
    onOpen: loadPermissionOptions,
});

function onModalHidden() {
    emit('close');
}

onMounted(() => {
    modalElement.value?.addEventListener('hidden.bs.modal', onModalHidden);
});

onUnmounted(() => {
    modalElement.value?.removeEventListener('hidden.bs.modal', onModalHidden);
    modalInstance?.dispose();
});
</script>

<style scoped>
.role-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.role-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
}

.role-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    flex-shrink: 0;
}

.role-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
    border-top: 1px solid var(--default-border, #dee2e6);
    background: var(--custom-white, #fff);
}

.role-modal-dialog {
    width: min(98vw, 1400px);
    max-width: min(98vw, 1400px);
    max-height: calc(100vh - 2rem);
    margin: 1rem auto;
    display: flex;
    flex-direction: column;
}

.modal.show .role-modal-dialog {
    max-height: calc(100vh - 2rem);
}

.role-modal-content {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
    max-height: calc(100vh - 2rem);
    overflow: hidden;
}

.modal.show .modal-content.role-modal-content {
    overflow: hidden;
}

.role-modal-header {
    flex-shrink: 0;
}

.role-modal-form {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
}

.role-modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-x: hidden;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}

.modal.show .modal-body.role-modal-body {
    overflow-x: hidden;
    overflow-y: auto;
}

.role-modal-footer {
    flex-shrink: 0;
    margin-top: auto;
}

.role-permission-panel {
    overflow: hidden;
    background: var(--custom-white, #fff);
}

.role-permissions-toolbar-strip {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    border-radius: 0.375rem;
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.06);
    border: 1px solid var(--default-border, #dee2e6);
}

.role-permissions-toolbar-global {
    width: 100%;
    justify-content: flex-end;
}

.role-permission-service-toolbar {
    width: 100%;
    justify-content: flex-end;
}

[dir='rtl'] .role-permissions-toolbar-global,
[dir='rtl'] .role-permission-service-toolbar {
    justify-content: flex-start;
}

.role-permission-bulk-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    font-weight: 500;
    white-space: nowrap;
}

.role-permission-bulk-btn i {
    font-size: 1.0625rem;
    line-height: 1;
    flex-shrink: 0;
}

.role-permission-bulk-btn--compact {
    padding: 0.2rem 0.45rem;
    font-size: 0.6875rem;
}

.role-permission-tabs .nav-link {
    font-size: 0.8125rem;
    padding: 0.5rem 0.85rem;
}

.role-permission-groups-row {
    align-items: stretch;
}

.role-permission-group {
    padding: 0.75rem;
    border: 1px solid var(--default-border, #dee2e6);
    border-radius: 0.5rem;
    background: var(--custom-white, #fff);
}

.role-permission-group__title {
    margin-bottom: 0.35rem;
    font-size: 0.875rem;
    font-weight: 600;
}

.role-permission-group__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem;
    margin-bottom: 0.65rem;
}

.role-permission-list {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.role-permission-check {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.25rem 0;
    margin-bottom: 0;
    min-height: auto;
    border: none;
    border-radius: 0;
}

.role-permission-check .form-check-input {
    margin-top: 0;
    flex-shrink: 0;
}

.role-permission-check .form-check-label {
    line-height: 1.35;
}
</style>
