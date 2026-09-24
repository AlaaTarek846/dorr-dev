<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_usage.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_usage.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_usage.title') }}</li>
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
                                        <th scope="col">{{ t('ai_usage.owner') }}</th>
                                        <th scope="col">{{ t('ai_usage.input_tokens') }}</th>
                                        <th scope="col">{{ t('ai_usage.output_tokens') }}</th>
                                        <th scope="col">{{ t('ai_usage.total_tokens') }}</th>
                                        <th scope="col">{{ t('ai_usage.total_cost') }}</th>
                                        <th scope="col">{{ t('ai_usage.usage_type') }}</th>
                                        <th scope="col">{{ t('ai_usage.created_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="7" />

                                    <tr v-else-if="!usages.length">
                                        <td colspan="7" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_usage.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="item in usages" v-else :key="item.id">
                                        <td>
                                            <span class="d-block fw-semibold">{{ item.owner?.name ?? '-' }}</span>
                                            <span class="d-block text-muted fs-11">#{{ item.owner?.id ?? '-' }}</span>
                                        </td>
                                        <td>{{ item.input_tokens }}</td>
                                        <td>{{ item.output_tokens }}</td>
                                        <td>{{ item.total_tokens }}</td>
                                        <td>{{ item.total_cost }}</td>
                                        <td>
                                            <span class="badge bg-secondary-transparent">{{ item.usage_type }}</span>
                                        </td>
                                        <td>{{ formatDateTime(item.created_at) }}</td>
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

const usages = ref([]);
const loading = ref(true);

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

async function loadUsages() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-usage');
        usages.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(() => loadUsages());
</script>
