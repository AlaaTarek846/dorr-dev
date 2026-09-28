<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_requests.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_requests.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_requests.title') }}</li>
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
                                        <th scope="col">{{ t('ai_requests.owner') }}</th>
                                        <th scope="col">{{ t('ai_requests.intent') }}</th>
                                        <th scope="col">{{ t('ai_requests.provider') }}</th>
                                        <th scope="col">{{ t('ai_requests.status') }}</th>
                                        <th scope="col">{{ t('ai_requests.retry_count') }}</th>
                                        <th scope="col">{{ t('ai_requests.created_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="6" />

                                    <tr v-else-if="!requests.length">
                                        <td colspan="6" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_requests.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="item in requests" v-else :key="item.id">
                                        <td>
                                            <span class="d-block fw-semibold">{{ item.owner?.name ?? '-' }}</span>
                                            <span class="d-block text-muted fs-11">#{{ item.owner?.id ?? '-' }}</span>
                                        </td>
                                        <td>{{ item.intent?.name ?? '-' }}</td>
                                        <td>{{ item.provider?.name ?? '-' }}</td>
                                        <td>
                                            <span class="badge" :class="statusBadgeClass(item.status)">{{ item.status }}</span>
                                        </td>
                                        <td>{{ item.retry_count }}</td>
                                        <td>{{ formatDateTime(item.created_at) }}</td>
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
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

const { t, locale } = useI18n();
const { showError } = useToast();

const requests = ref([]);
const loading = ref(true);

const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function statusBadgeClass(status) {
    return {
        pending: 'bg-secondary-transparent',
        processing: 'bg-info-transparent',
        completed: 'bg-success-transparent',
        failed: 'bg-danger-transparent',
        cancelled: 'bg-secondary-transparent',
        blocked: 'bg-dark-transparent',
        retrying: 'bg-warning-transparent',
    }[status] ?? 'bg-secondary-transparent';
}

async function loadRequests() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-requests', { params: paginationParams.value });
        requests.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function onChangePage(target) {
    page.value = target;
    loadRequests();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadRequests();
}

onMounted(() => loadRequests());
</script>
