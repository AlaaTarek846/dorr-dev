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
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="5" />

                                    <tr v-else-if="!scans.length">
                                        <td colspan="5" class="border-0">
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
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

const { t, locale } = useI18n();
const { showError } = useToast();

const scans = ref([]);
const loading = ref(true);

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
        const { data } = await adminAxios.get('/api/admin/v1/ai-safety-scans');
        scans.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(() => loadScans());
</script>
