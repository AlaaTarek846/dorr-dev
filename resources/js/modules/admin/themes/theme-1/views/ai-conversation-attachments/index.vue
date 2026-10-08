<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_conversation_attachments.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_conversation_attachments.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_conversation_attachments.title') }}</li>
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
                                        <th scope="col">{{ t('ai_conversation_attachments.conversation') }}</th>
                                        <th scope="col">{{ t('ai_conversation_attachments.message_id') }}</th>
                                        <th scope="col">{{ t('ai_conversation_attachments.file_name') }}</th>
                                        <th scope="col">{{ t('ai_conversation_attachments.mime_type') }}</th>
                                        <th scope="col">{{ t('ai_conversation_attachments.file_size') }}</th>
                                        <th scope="col">{{ t('ai_conversation_attachments.created_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="6" />

                                    <tr v-else-if="!attachments.length">
                                        <td colspan="6" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_conversation_attachments.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="attachment in attachments" v-else :key="attachment.id">
                                        <td>
                                            <span class="d-block fw-semibold">{{ attachment.conversation?.title || '-' }}</span>
                                            <span class="d-block text-muted fs-11">#{{ attachment.conversation?.id ?? '-' }}</span>
                                        </td>
                                        <td>{{ attachment.message_id ?? '-' }}</td>
                                        <td>{{ attachment.file_name }}</td>
                                        <td>{{ attachment.mime_type || '-' }}</td>
                                        <td>{{ formatSize(attachment.file_size) }}</td>
                                        <td>{{ formatDateTime(attachment.created_at) }}</td>
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

const attachments = ref([]);
const loading = ref(true);

const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

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

async function loadAttachments() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-conversation-attachments', { params: paginationParams.value });
        attachments.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function onChangePage(target) {
    page.value = target;
    loadAttachments();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadAttachments();
}

onMounted(() => loadAttachments());
</script>
