<template>
    <div class="card custom-card provider-card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="avatar avatar-md avatar-rounded bg-primary-transparent">
                    <i class="ri-user-settings-line text-primary"></i>
                </span>
                <div>
                    <div class="card-title mb-0">
                        {{ provider.user?.name || '-' }}
                        <span v-if="provider.business_name" class="fs-12 text-muted">· {{ provider.business_name }}</span>
                    </div>
                    <div class="fs-12 text-muted">
                        {{ provider.user?.email }}
                        <span v-if="provider.user?.phone">· {{ provider.user.phone }}</span>
                    </div>
                </div>
            </div>

            <span class="badge fs-12" :class="statusBadgeClass">{{ statusLabel }}</span>
        </div>

        <div class="card-body">
            <div v-if="provider.status === 'rejected' && provider.rejection_reason" class="alert alert-danger py-2 px-3 fs-13 mb-3">
                <strong>{{ t('providers.rejection_reason') }}:</strong> {{ provider.rejection_reason }}
            </div>

            <div v-if="provider.status === 'approved' && provider.approver" class="fs-12 text-muted mb-3">
                <i class="ri-shield-check-line align-middle text-success"></i>
                {{ t('providers.approved_by') }}: {{ provider.approver.name }}
            </div>

            <div class="mb-2 d-flex align-items-center justify-content-between">
                <span class="fw-semibold fs-13">{{ t('providers.services_title') }}</span>
                <span class="fs-11 text-muted">{{ t('service_categories.title') }}: {{ provider.services?.length ?? 0 }}</span>
            </div>

            <div v-if="!provider.services?.length" class="text-muted fs-13 mb-3">
                {{ t('providers.no_services') }}
            </div>

            <ul v-else class="list-unstyled mb-3 provider-services-list">
                <li
                    v-for="service in provider.services"
                    :key="service.id"
                    class="d-flex align-items-center justify-content-between gap-2 provider-service-row"
                >
                    <div>
                        <span class="fw-semibold fs-13">{{ categoryName(service.category) }}</span>
                        <span class="badge fs-11 ms-1" :class="serviceStatusBadgeClass(service.status)">
                            {{ serviceStatusLabel(service.status) }}
                        </span>
                    </div>
                    <div v-if="service.status === 'pending'" class="btn-list">
                        <button
                            type="button"
                            class="btn btn-sm btn-success-light btn-icon"
                            :disabled="busyServiceId === service.id"
                            :title="t('providers.approve')"
                            @click="approveService(service)"
                        >
                            <i class="ri-check-line"></i>
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-danger-light btn-icon"
                            :disabled="busyServiceId === service.id"
                            :title="t('providers.reject')"
                            @click="rejectService(service)"
                        >
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <select v-model="newCategoryId" class="form-select form-select-sm">
                    <option :value="null">{{ t('providers.select_service') }}</option>
                    <option v-for="option in leafOptions" :key="option.id" :value="option.id">
                        {{ locale === 'ar' ? (option.name || option.name_en) : (option.name_en || option.name) }}
                    </option>
                </select>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary text-nowrap"
                    :disabled="!newCategoryId || addingService"
                    @click="addService"
                >
                    <span v-if="addingService" class="spinner-border spinner-border-sm me-1"></span>
                    {{ t('providers.add_service') }}
                </button>
            </div>

            <div class="d-flex align-items-center justify-content-between pt-3 mt-3 border-top">
                <span class="fs-11 text-muted">{{ t('providers.registered_at') }}: {{ formatDate(provider.created_at) }}</span>

                <div class="btn-list">
                    <button
                        v-if="provider.status === 'pending' || provider.status === 'rejected'"
                        type="button"
                        class="btn btn-sm btn-success btn-wave"
                        :disabled="accountBusy"
                        @click="approveAccount"
                    >
                        {{ t('providers.approve') }}
                    </button>
                    <button
                        v-if="provider.status === 'pending'"
                        type="button"
                        class="btn btn-sm btn-danger btn-wave"
                        :disabled="accountBusy"
                        @click="$emit('reject', provider)"
                    >
                        {{ t('providers.reject') }}
                    </button>
                    <button
                        v-if="provider.status === 'approved'"
                        type="button"
                        class="btn btn-sm btn-warning btn-wave"
                        :disabled="accountBusy"
                        @click="suspendAccount"
                    >
                        {{ t('providers.suspend') }}
                    </button>
                    <button
                        v-if="provider.status === 'suspended'"
                        type="button"
                        class="btn btn-sm btn-primary btn-wave"
                        :disabled="accountBusy"
                        @click="reactivateAccount"
                    >
                        {{ t('providers.reactivate') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../composables/useToast';

const props = defineProps({
    provider: {
        type: Object,
        required: true,
    },
    leafOptions: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['updated', 'reject']);

const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();

const accountBusy = ref(false);
const busyServiceId = ref(null);
const addingService = ref(false);
const newCategoryId = ref(null);

const STATUS_META = {
    pending: { badge: 'bg-warning-transparent', label: 'providers.tab_pending' },
    approved: { badge: 'bg-success-transparent', label: 'providers.tab_approved' },
    rejected: { badge: 'bg-danger-transparent', label: 'providers.tab_rejected' },
    suspended: { badge: 'bg-secondary-transparent', label: 'providers.tab_suspended' },
};

const SERVICE_STATUS_META = {
    pending: { badge: 'bg-warning-transparent', label: 'providers.service_status_pending' },
    approved: { badge: 'bg-success-transparent', label: 'providers.service_status_approved' },
    rejected: { badge: 'bg-danger-transparent', label: 'providers.service_status_rejected' },
};

const statusBadgeClass = computed(() => STATUS_META[props.provider.status]?.badge ?? 'bg-secondary-transparent');
const statusLabel = computed(() => t(STATUS_META[props.provider.status]?.label ?? 'providers.tab_pending'));

function serviceStatusBadgeClass(status) {
    return SERVICE_STATUS_META[status]?.badge ?? 'bg-secondary-transparent';
}

function serviceStatusLabel(status) {
    return t(SERVICE_STATUS_META[status]?.label ?? 'providers.service_status_pending');
}

function categoryName(category) {
    if (! category) {
        return '-';
    }

    return locale.value === 'ar' ? (category.name_ar || category.name_en) : (category.name_en || category.name_ar);
}

function formatDate(value) {
    if (! value) {
        return '-';
    }

    return new Date(value).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

async function callAction(url, { successMessage } = {}) {
    try {
        const response = await adminAxios.post(url);
        emit('updated', response.data.data);
        showSuccess(successMessage ?? extractApiMessage(response, t('toast.updated')));

        return true;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));

        return false;
    }
}

async function approveAccount() {
    accountBusy.value = true;
    await callAction(`/api/admin/v1/providers/${props.provider.id}/approve`);
    accountBusy.value = false;
}

async function suspendAccount() {
    accountBusy.value = true;
    await callAction(`/api/admin/v1/providers/${props.provider.id}/suspend`);
    accountBusy.value = false;
}

async function reactivateAccount() {
    accountBusy.value = true;
    await callAction(`/api/admin/v1/providers/${props.provider.id}/reactivate`);
    accountBusy.value = false;
}

async function approveService(service) {
    busyServiceId.value = service.id;
    await callAction(`/api/admin/v1/providers/${props.provider.id}/services/${service.id}/approve`);
    busyServiceId.value = null;
}

async function rejectService(service) {
    busyServiceId.value = service.id;
    await callAction(`/api/admin/v1/providers/${props.provider.id}/services/${service.id}/reject`);
    busyServiceId.value = null;
}

async function addService() {
    if (! newCategoryId.value) {
        return;
    }

    addingService.value = true;

    try {
        const response = await adminAxios.post(`/api/admin/v1/providers/${props.provider.id}/services`, {
            service_category_id: newCategoryId.value,
        });

        // The endpoint only returns the new service row, so refetch the
        // full profile to get the updated services list back in one piece.
        const { data } = await adminAxios.get(`/api/admin/v1/providers/${props.provider.id}`);
        emit('updated', data.data);
        newCategoryId.value = null;
        showSuccess(extractApiMessage(response, t('toast.created')));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        addingService.value = false;
    }
}
</script>

<style scoped>
.provider-service-row {
    padding: 0.4rem 0;
    border-bottom: 1px dashed var(--default-border, #f1f3f5);
}

.provider-service-row:last-child {
    border-bottom: 0;
}
</style>
