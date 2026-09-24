<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_provider_health.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_provider_health.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_provider_health.title') }}</li>
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
                                        <th scope="col">{{ t('ai_provider_health.provider') }}</th>
                                        <th scope="col">{{ t('ai_provider_health.check_type') }}</th>
                                        <th scope="col">{{ t('ai_provider_health.health_score') }}</th>
                                        <th scope="col">{{ t('ai_provider_health.status') }}</th>
                                        <th scope="col">{{ t('ai_provider_health.created_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="5" />

                                    <tr v-else-if="!checks.length">
                                        <td colspan="5" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_provider_health.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="check in checks" v-else :key="check.id">
                                        <td>{{ check.provider?.name || '-' }}</td>
                                        <td>
                                            <span class="badge bg-secondary-transparent">{{ check.check_type }}</span>
                                        </td>
                                        <td>{{ check.health_score }}</td>
                                        <td>
                                            <span class="badge" :class="statusBadgeClass(check.status)">{{ check.status }}</span>
                                        </td>
                                        <td>{{ formatDateTime(check.created_at) }}</td>
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

const checks = ref([]);
const loading = ref(true);

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function statusBadgeClass(status) {
    return {
        healthy: 'bg-success-transparent',
        degraded: 'bg-warning-transparent',
        unhealthy: 'bg-danger-transparent',
        recovering: 'bg-info-transparent',
    }[status] ?? 'bg-secondary-transparent';
}

async function loadChecks() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-provider-health');
        checks.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(() => loadChecks());
</script>
