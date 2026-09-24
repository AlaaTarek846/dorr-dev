<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_code_executions.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_code_executions.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_code_executions.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ t('ai_code_executions.request_id') }}</th>
                                        <th scope="col">{{ t('ai_code_executions.language') }}</th>
                                        <th scope="col">{{ t('ai_code_executions.driver') }}</th>
                                        <th scope="col">{{ t('ai_code_executions.attempt_number') }}</th>
                                        <th scope="col">{{ t('ai_code_executions.duration') }}</th>
                                        <th scope="col">{{ t('ai_code_executions.status') }}</th>
                                        <th scope="col">{{ t('ai_code_executions.created_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_code_executions.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="6" :columns="8" />

                                    <tr v-else-if="!executions.length">
                                        <td colspan="8" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_code_executions.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="execution in executions" v-else :key="execution.id">
                                        <td>{{ execution.request_id ? '#' + execution.request_id : '-' }}</td>
                                        <td><span class="badge bg-secondary-transparent">{{ execution.language }}</span></td>
                                        <td>{{ execution.driver }}</td>
                                        <td>{{ execution.attempt_number }}</td>
                                        <td>{{ execution.duration_ms !== null ? execution.duration_ms + ' ms' : '-' }}</td>
                                        <td>
                                            <span class="badge" :class="statusBadgeClass(execution.status)">
                                                {{ t('ai_code_executions.status_' + execution.status) }}
                                            </span>
                                        </td>
                                        <td>{{ formatDateTime(execution.created_at) }}</td>
                                        <td class="text-end pe-4">
                                            <button type="button" class="btn btn-sm btn-info-light btn-icon" @click="openDetails(execution)">
                                                <i class="ri-eye-line"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content" v-if="selected">
                    <div class="modal-header catalog-modal-header">
                        <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                            <h6 class="modal-title mb-0">{{ t('ai_code_executions.details_title') }} #{{ selected.id }}</h6>
                            <button type="button" class="btn-close" aria-label="Close" @click="closeModal"></button>
                        </div>
                    </div>
                    <div class="modal-body px-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">{{ t('ai_code_executions.code') }}</label>
                            <pre class="bg-light p-2 rounded fs-12" style="max-height: 220px; overflow:auto; white-space: pre-wrap;">{{ selected.code }}</pre>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">{{ t('ai_code_executions.stdout') }}</label>
                            <pre class="bg-light p-2 rounded fs-12" style="max-height: 160px; overflow:auto; white-space: pre-wrap;">{{ selected.stdout || '-' }}</pre>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">{{ t('ai_code_executions.stderr') }}</label>
                            <pre class="bg-light p-2 rounded fs-12 text-danger" style="max-height: 160px; overflow:auto; white-space: pre-wrap;">{{ selected.stderr || '-' }}</pre>
                        </div>
                    </div>
                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="closeModal">{{ t('close') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

const { t, locale } = useI18n();
const { showError } = useToast();

const executions = ref([]);
const loading = ref(true);
const selected = ref(null);
const modalElement = ref(null);
let modalInstance = null;

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function statusBadgeClass(status) {
    return {
        completed: 'bg-success-transparent',
        failed: 'bg-danger-transparent',
        timeout: 'bg-warning-transparent',
        unavailable: 'bg-secondary-transparent',
        pending: 'bg-info-transparent',
    }[status] || 'bg-light text-muted';
}

async function loadExecutions() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-code-executions');
        executions.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function openDetails(execution) {
    selected.value = execution;

    if (! modalElement.value) return;
    modalInstance ??= new window.bootstrap.Modal(modalElement.value, { focus: false });
    modalInstance.show();
}

function closeModal() {
    modalInstance?.hide();
}

onMounted(() => {
    loadExecutions();
    modalElement.value?.addEventListener('hidden.bs.modal', () => { selected.value = null; });
});

onUnmounted(() => {
    modalInstance?.dispose();
});
</script>
