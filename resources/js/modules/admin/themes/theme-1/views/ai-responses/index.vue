<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_responses.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_responses.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_responses.title') }}</li>
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
                                        <th scope="col">{{ t('ai_responses.request_id') }}</th>
                                        <th scope="col">{{ t('ai_responses.response') }}</th>
                                        <th scope="col">{{ t('ai_responses.finish_reason') }}</th>
                                        <th scope="col">{{ t('ai_responses.created_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="4" />

                                    <tr v-else-if="!responses.length">
                                        <td colspan="4" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_responses.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="item in responses" v-else :key="item.id">
                                        <td>#{{ item.request_id }}</td>
                                        <td class="text-muted" style="max-width: 420px; white-space: normal;">
                                            {{ truncate(item.response?.content || item.response?.message) }}
                                        </td>
                                        <td>
                                            <span class="badge" :class="finishBadgeClass(item.finish_reason)">{{ item.finish_reason }}</span>
                                        </td>
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

const responses = ref([]);
const loading = ref(true);

const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function truncate(text, length = 140) {
    if (! text) return '-';
    return text.length > length ? `${text.slice(0, length)}...` : text;
}

function finishBadgeClass(reason) {
    return {
        completed: 'bg-success-transparent',
        length_limit: 'bg-warning-transparent',
        tool_call: 'bg-info-transparent',
        error: 'bg-danger-transparent',
    }[reason] ?? 'bg-secondary-transparent';
}

async function loadResponses() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-responses', { params: paginationParams.value });
        responses.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function onChangePage(target) {
    page.value = target;
    loadResponses();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadResponses();
}

onMounted(() => loadResponses());
</script>
