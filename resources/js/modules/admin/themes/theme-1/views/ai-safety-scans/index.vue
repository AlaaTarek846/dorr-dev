<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_safety_scans.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_safety_scans.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_safety_scans.title') }}</li>
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
                                        <th scope="col">{{ t('ai_safety_scans.owner') }}</th>
                                        <th scope="col">{{ t('ai_safety_scans.target_type') }}</th>
                                        <th scope="col">{{ t('ai_safety_scans.scan_type') }}</th>
                                        <th scope="col">{{ t('ai_safety_scans.decision') }}</th>
                                        <th scope="col">{{ t('ai_safety_scans.created_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="6" />

                                    <tr v-else-if="!scans.length">
                                        <td colspan="6" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_safety_scans.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="scan in scans" v-else :key="scan.id">
                                        <td>
                                            <span class="d-block fw-semibold">{{ scan.owner?.name ?? '-' }}</span>
                                            <span class="d-block text-muted fs-11">#{{ scan.owner?.id ?? '-' }}</span>
                                        </td>
                                        <td>{{ scan.target_type }}</td>
                                        <td>{{ scan.scan_type }}</td>
                                        <td>
                                            <span class="badge" :class="decisionBadgeClass(scan.decision)">
                                                {{ scan.decision }}
                                            </span>
                                        </td>
                                        <td>{{ formatDateTime(scan.created_at) }}</td>
                                        <td class="text-end pe-4">
                                            <button type="button" class="btn btn-sm btn-info-light btn-icon" @click="openDetails(scan)">
                                                <i class="ri-eye-line"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <AdminPaginationFooter
                        :pagination="pagination"
                        :current-page="page"
                        :per-page="perPage"
                        :loading="loading"
                        @change-page="onChangePage"
                        @change-per-page="onChangePerPage"
                    />
                </div>
            </div>
        </div>

        <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content" v-if="selected">
                    <div class="modal-header catalog-modal-header">
                        <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                            <h6 class="modal-title mb-0">{{ t('ai_safety_scans.details_title') }} #{{ selected.id }}</h6>
                            <button type="button" class="btn-close" aria-label="Close" @click="closeModal"></button>
                        </div>
                    </div>
                    <div class="modal-body px-4">
                        <div class="mb-3">
                            <span class="badge" :class="decisionBadgeClass(selected.decision)">{{ selected.decision }}</span>
                        </div>
                        <div class="mb-1">
                            <label class="form-label fw-semibold">{{ t('ai_safety_scans.findings') }}</label>
                        </div>
                        <pre
                            v-if="selected.findings && Object.keys(selected.findings).length"
                            class="bg-light p-2 rounded fs-12"
                            style="max-height: 320px; overflow:auto; white-space: pre-wrap;"
                        >{{ JSON.stringify(selected.findings, null, 2) }}</pre>
                        <p v-else class="text-muted mb-0">{{ t('ai_safety_scans.no_findings') }}</p>
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
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

/**
 * Business gap fix: `findings` (why a scan actually passed/blocked/needed
 * review) has always been returned in full by the API
 * (AiSafetyScanResource), but this screen never rendered it anywhere - the
 * admin only ever saw the decision badge, with no way to see the reason
 * behind it. Added a details modal, same pattern as ai-code-executions'
 * own details view.
 */

const { t, locale } = useI18n();
const { showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const scans = ref([]);
const loading = ref(true);
const selected = ref(null);
const modalElement = ref(null);
let modalInstance = null;

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function decisionBadgeClass(decision) {
    return {
        passed: 'bg-success-transparent',
        blocked: 'bg-danger-transparent',
        sanitized: 'bg-secondary-transparent',
        review_required: 'bg-warning-transparent',
    }[decision] ?? 'bg-secondary-transparent';
}

async function loadScans() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-safety-scans', { params: paginationParams.value });
        scans.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function openDetails(scan) {
    selected.value = scan;

    if (! modalElement.value) return;
    modalInstance ??= new window.bootstrap.Modal(modalElement.value, { focus: false });
    modalInstance.show();
}

function closeModal() {
    modalInstance?.hide();
}

function onChangePage(target) {
    page.value = target;
    loadScans();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadScans();
}

onMounted(() => {
    loadScans();
    modalElement.value?.addEventListener('hidden.bs.modal', () => { selected.value = null; });
});

onUnmounted(() => {
    modalInstance?.dispose();
});
</script>
