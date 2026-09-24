<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_reliability_metrics.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_reliability_metrics.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_reliability_metrics.title') }}</li>
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
                                        <th scope="col">{{ t('ai_reliability_metrics.provider') }}</th>
                                        <th scope="col">{{ t('ai_reliability_metrics.intent') }}</th>
                                        <th scope="col">{{ t('ai_reliability_metrics.period') }}</th>
                                        <th scope="col">{{ t('ai_reliability_metrics.success_rate') }}</th>
                                        <th scope="col">{{ t('ai_reliability_metrics.error_rate') }}</th>
                                        <th scope="col">{{ t('ai_reliability_metrics.p95') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="6" />

                                    <tr v-else-if="!metrics.length">
                                        <td colspan="6" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_reliability_metrics.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="metric in metrics" v-else :key="metric.id">
                                        <td>{{ metric.provider?.name || '-' }}</td>
                                        <td>{{ metric.intent?.name || '-' }}</td>
                                        <td>{{ formatDate(metric.period_start) }} - {{ formatDate(metric.period_end) }}</td>
                                        <td>
                                            <span class="badge bg-success-transparent">{{ metric.success_rate }}%</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-danger-transparent">{{ metric.error_rate }}%</span>
                                        </td>
                                        <td>{{ metric.latency_p95_ms != null ? `${metric.latency_p95_ms} ms` : '-' }}</td>
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

const metrics = ref([]);
const loading = ref(true);

function formatDate(value) {
    if (! value) return '-';
    return new Date(value).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

async function loadMetrics() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-reliability-metrics');
        metrics.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(() => loadMetrics());
</script>
