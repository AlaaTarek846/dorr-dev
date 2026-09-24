<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_verifications.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_verifications.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_verifications.title') }}</li>
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
                                        <th scope="col">{{ t('ai_verifications.request_id') }}</th>
                                        <th scope="col">{{ t('ai_verifications.attempt_number') }}</th>
                                        <th scope="col">{{ t('ai_verifications.verifier_provider') }}</th>
                                        <th scope="col">{{ t('ai_verifications.confidence_score') }}</th>
                                        <th scope="col">{{ t('ai_verifications.supported_claims_ratio') }}</th>
                                        <th scope="col">{{ t('ai_verifications.status') }}</th>
                                        <th scope="col">{{ t('ai_verifications.created_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="7" />

                                    <tr v-else-if="!verifications.length">
                                        <td colspan="7" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_verifications.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="verification in verifications" v-else :key="verification.id">
                                        <td>#{{ verification.request_id }}</td>
                                        <td>{{ verification.attempt_number }}</td>
                                        <td>{{ verification.verifier_provider?.name || '-' }}</td>
                                        <td>{{ formatScore(verification.confidence_score) }}</td>
                                        <td>{{ formatScore(verification.supported_claims_ratio) }}</td>
                                        <td>
                                            <span class="badge" :class="statusBadgeClass(verification.status)">
                                                {{ t('ai_verifications.status_' + verification.status) }}
                                            </span>
                                        </td>
                                        <td>{{ formatDateTime(verification.created_at) }}</td>
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

const verifications = ref([]);
const loading = ref(true);

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function formatScore(value) {
    if (value === null || value === undefined) return '-';
    return Math.round(value * 100) + '%';
}

function statusBadgeClass(status) {
    return {
        passed: 'bg-success-transparent',
        needs_correction: 'bg-warning-transparent',
        failed: 'bg-danger-transparent',
        abstained: 'bg-secondary-transparent',
        skipped: 'bg-light text-muted',
        pending: 'bg-info-transparent',
    }[status] || 'bg-light text-muted';
}

async function loadVerifications() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-verifications');
        verifications.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(() => loadVerifications());
</script>
