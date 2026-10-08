<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_files.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_files.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_files.title') }}</li>
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
                                        <th scope="col">{{ t('ai_files.owner') }}</th>
                                        <th scope="col">{{ t('ai_files.file_name') }}</th>
                                        <th scope="col">{{ t('ai_files.source_type') }}</th>
                                        <th scope="col">{{ t('ai_files.file_size') }}</th>
                                        <th scope="col">{{ t('ai_files.processing_status') }}</th>
                                        <th scope="col">{{ t('ai_files.created_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="6" />

                                    <tr v-else-if="!files.length">
                                        <td colspan="6" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_files.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="file in files" v-else :key="file.id">
                                        <td>
                                            <span class="d-block fw-semibold">{{ ownerDisplayName(file.owner) }}</span>
                                            <span class="d-block text-muted fs-11">#{{ file.owner?.id ?? '-' }}</span>
                                        </td>
                                        <td>{{ file.file_name }}</td>
                                        <td>
                                            <span class="badge bg-secondary-transparent">{{ file.source_type }}</span>
                                        </td>
                                        <td>{{ formatSize(file.file_size) }}</td>
                                        <td>
                                            <span class="badge" :class="statusBadgeClass(file.processing_status)">{{ file.processing_status }}</span>
                                        </td>
                                        <td>{{ formatDateTime(file.created_at) }}</td>
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

const files = ref([]);
const loading = ref(true);

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function formatSize(bytes) {
    if (! bytes && bytes !== 0) return '-';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function statusBadgeClass(status) {
    return {
        pending: 'bg-secondary-transparent',
        processing: 'bg-info-transparent',
        ready: 'bg-success-transparent',
        rejected: 'bg-danger-transparent',
    }[status] ?? 'bg-secondary-transparent';
}

async function loadFiles() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-files', { params: paginationParams.value });
        files.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function onChangePage(target) {
    page.value = target;
    loadFiles();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadFiles();
}

onMounted(() => loadFiles());
</script>
