<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header user-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">
                            {{ modalTitle }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close user-modal-close"
                            aria-label="Close"
                            @click="close"
                        ></button>
                    </div>
                </div>

                <form @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <div class="row gy-3">
                            <div class="col-md-6">
                                <label class="form-label">
                                    {{ t('users.name') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-user-line"></i>
                                    </span>
                                    <input
                                        v-model="form.name"
                                        type="text"
                                        class="form-control"
                                        :class="{ 'is-invalid': errors.name?.[0] }"
                                    >
                                </div>
                                <div v-if="errors.name?.[0]" class="invalid-feedback d-block">{{ errors.name[0] }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    {{ t('email') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-mail-line"></i>
                                    </span>
                                    <input
                                        v-model="form.email"
                                        type="email"
                                        class="form-control"
                                        :class="{ 'is-invalid': errors.email?.[0] }"
                                    >
                                </div>
                                <div v-if="errors.email?.[0]" class="invalid-feedback d-block">{{ errors.email[0] }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">{{ t('users.phone') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-phone-line"></i>
                                    </span>
                                    <input
                                        v-model="form.phone"
                                        type="text"
                                        class="form-control"
                                        :class="{ 'is-invalid': errors.phone?.[0] }"
                                    >
                                </div>
                                <div v-if="errors.phone?.[0]" class="invalid-feedback d-block">{{ errors.phone[0] }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">{{ t('users.status') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-toggle-line"></i>
                                    </span>
                                    <select
                                        v-model="form.status"
                                        class="form-select"
                                        :class="{ 'is-invalid': errors.status?.[0] }"
                                    >
                                        <option
                                            v-for="(label, value) in statusOptions"
                                            :key="value"
                                            :value="value"
                                        >
                                            {{ label }}
                                        </option>
                                    </select>
                                </div>
                                <div v-if="errors.status?.[0]" class="invalid-feedback d-block">{{ errors.status[0] }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    {{ t('password') }}
                                    <span v-if="type === 'create'" class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-lock-line"></i>
                                    </span>
                                    <input
                                        v-model="form.password"
                                        type="password"
                                        class="form-control"
                                        :class="{ 'is-invalid': errors.password?.[0] }"
                                    >
                                </div>
                                <div v-if="errors.password?.[0]" class="invalid-feedback d-block">{{ errors.password[0] }}</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    {{ t('users.password_confirmation') }}
                                    <span v-if="type === 'create'" class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="ri-lock-password-line"></i>
                                    </span>
                                    <input
                                        v-model="form.password_confirmation"
                                        type="password"
                                        class="form-control"
                                        :class="{ 'is-invalid': errors.password_confirmation?.[0] }"
                                    >
                                </div>
                                <div v-if="errors.password_confirmation?.[0]" class="invalid-feedback d-block">{{ errors.password_confirmation[0] }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer user-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('cancel') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('saving') : t('save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../composables/useToast';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    type: {
        type: String,
        default: 'create',
    },
    record: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const modalElement = ref(null);
const submitting = ref(false);
const errors = reactive({});
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    name: '',
    email: '',
    phone: '',
    status: 'active',
    password: '',
    password_confirmation: '',
});

const statusOptions = computed(() => ({
    active: t('users.filter_active'),
    inactive: t('users.filter_inactive'),
    blocked: t('users.filter_blocked'),
}));

const modalTitle = computed(() => {
    if (! isEdit.value) {
        return t('users.create_title');
    }

    const record = props.record;

    if (! record?.id) {
        return t('users.edit_title');
    }

    return record.name
        ? `${t('users.edit_title')} #${record.id} ${record.name}`
        : `${t('users.edit_title')} #${record.id}`;
});

function resetErrors() {
    Object.keys(errors).forEach((key) => {
        errors[key] = null;
    });
}

function fillForm(record) {
    form.name = record?.name ?? '';
    form.email = record?.email ?? '';
    form.phone = record?.phone ?? '';
    form.status = record?.status ?? 'active';
    form.password = '';
    form.password_confirmation = '';
}

function resetForm() {
    fillForm(null);
}

function openModal() {
    if (! modalElement.value) {
        return;
    }

    modalInstance ??= new window.bootstrap.Modal(modalElement.value);
    modalInstance.show();
}

function closeModal() {
    modalInstance?.hide();
}

function close() {
    closeModal();
    emit('close');
}

function onModalHidden() {
    emit('close');
}

async function submit() {
    resetErrors();
    submitting.value = true;

    const payload = {
        name: form.name,
        email: form.email,
        phone: form.phone || null,
        status: form.status,
    };

    if (form.password) {
        payload.password = form.password;
        payload.password_confirmation = form.password_confirmation;
    }

    try {
        const response = isEdit.value && props.record?.id
            ? await adminAxios.put(`/api/admin/v1/users/${props.record.id}`, payload)
            : await adminAxios.post('/api/admin/v1/users', payload);

        showSuccess(extractApiMessage(response, t('save')));
        closeModal();
        emit('saved');
    } catch (error) {
        if (error.response?.status === 422) {
            Object.assign(errors, error.response.data.errors ?? {});
        } else {
            showError(extractApiErrorMessage(error));
        }
    } finally {
        submitting.value = false;
    }
}

watch(
    () => props.show,
    (visible) => {
        if (visible) {
            resetErrors();

            if (isEdit.value && props.record) {
                fillForm(props.record);
            } else {
                resetForm();
            }

            openModal();
        } else {
            closeModal();
        }
    },
);

watch(
    () => props.record,
    (record) => {
        if (props.show && isEdit.value && record) {
            fillForm(record);
        }
    },
);

onMounted(() => {
    modalElement.value?.addEventListener('hidden.bs.modal', onModalHidden);
});

onUnmounted(() => {
    modalElement.value?.removeEventListener('hidden.bs.modal', onModalHidden);
    modalInstance?.dispose();
});
</script>

<style scoped>
.user-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.user-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.user-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    flex-shrink: 0;
    opacity: 0.65;
    background-size: 0.65rem;
}

.user-modal-close:hover {
    opacity: 1;
}

.user-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
}
</style>
