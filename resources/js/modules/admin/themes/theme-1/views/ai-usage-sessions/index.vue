<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_usage_sessions.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_usage_sessions.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_usage_sessions.title') }}</li>
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
                                        <th scope="col">{{ t('ai_usage_sessions.owner') }}</th>
                                        <th scope="col">{{ t('ai_usage_sessions.plan') }}</th>
                                        <th scope="col">{{ t('ai_usage_sessions.started_at') }}</th>
                                        <th scope="col">{{ t('ai_usage_sessions.ended_at') }}</th>
                                        <th scope="col">{{ t('ai_usage_sessions.duration') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="5" />

                                    <tr v-else-if="!sessions.length">
                                        <td colspan="5" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_usage_sessions.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="session in sessions" v-else :key="session.id">
                                        <td>
                                            <span class="d-block fw-semibold">{{ session.owner?.name ?? '-' }}</span>
                                            <span class="d-block text-muted fs-11">#{{ session.owner?.id }}</span>
                                        </td>
                                        <td>{{ session.subscription?.plan?.name ?? '-' }}</td>
                                        <td>{{ formatDateTime(session.started_at) }}</td>
                                        <td>{{ session.ended_at ? formatDateTime(session.ended_at) : '-' }}</td>
                                        <td>{{ formatDuration(session.duration_seconds) }}</td>
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
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';

const { t, locale } = useI18n();
const { showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const sessions = ref([]);
const loading = ref(true);

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function formatDuration(seconds) {
    const total = Number(seconds ?? 0);
    const minutes = Math.floor(total / 60);
    const remaining = total % 60;
    return `${minutes}m ${remaining}s`;
}

async function loadSessions() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-usage-sessions', { params: paginationParams.value });
        sessions.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function onChangePage(target) {
    page.value = target;
    loadSessions();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadSessions();
}

onMounted(() => loadSessions());
</script>
