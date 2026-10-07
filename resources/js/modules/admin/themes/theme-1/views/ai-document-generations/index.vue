<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_document_generations.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_document_generations.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_document_generations.title') }}</li>
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
                                        <th scope="col">{{ t('ai_document_generations.owner') }}</th>
                                        <th scope="col">{{ t('ai_document_generations.output_format') }}</th>
                                        <th scope="col">{{ t('ai_document_generations.output_file_name') }}</th>
                                        <th scope="col">{{ t('ai_document_generations.status') }}</th>
                                        <th scope="col">{{ t('ai_document_generations.created_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="5" />

                                    <tr v-else-if="!generations.length">
                                        <td colspan="5" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_document_generations.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="generation in generations" v-else :key="generation.id">
                                        <td>
                                            <span class="d-block fw-semibold">{{ ownerDisplayName(generation.owner) }}</span>
                                            <span class="d-block text-muted fs-11">#{{ generation.owner?.id ?? '-' }}</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-transparent">{{ generation.output_format }}</span>
                                        </td>
                                        <td>{{ generation.output_file_name || '-' }}</td>
                                        <td>
                                            <span class="badge" :class="statusBadgeClass(generation.status)">{{ generation.status }}</span>
                                        </td>
                                        <td>{{ formatDateTime(generation.created_at) }}</td>
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
import { ownerDisplayName } from '../../../../../../utils/aiOwner';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';

const { t, locale } = useI18n();
const { showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const generations = ref([]);
const loading = ref(true);

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function statusBadgeClass(status) {
    return {
        pending: 'bg-secondary-transparent',
        generating: 'bg-info-transparent',
        preview_ready: 'bg-warning-transparent',
        completed: 'bg-success-transparent',
        failed: 'bg-danger-transparent',
    }[status] ?? 'bg-secondary-transparent';
}

async function loadGenerations() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-document-generations', { params: paginationParams.value });
        generations.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function onChangePage(target) {
    page.value = target;
    loadGenerations();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadGenerations();
}

onMounted(() => loadGenerations());
</script>
