<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_project_context.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_project_context.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_project_context.title') }}</li>
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
                                        <th scope="col">{{ t('ai_project_context.project') }}</th>
                                        <th scope="col">{{ t('ai_project_context.context_type') }}</th>
                                        <th scope="col">{{ t('ai_project_context.content') }}</th>
                                        <th scope="col">{{ t('ai_project_context.created_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="4" />

                                    <tr v-else-if="!contextItems.length">
                                        <td colspan="4" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_project_context.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="item in contextItems" v-else :key="item.id">
                                        <td>
                                            <span class="d-block fw-semibold">{{ item.project?.name || '-' }}</span>
                                            <span class="d-block text-muted fs-11">#{{ item.project?.id ?? '-' }}</span>
                                        </td>
                                        <td>
                                            <span class="badge" :class="typeBadgeClass(item.context_type)">{{ item.context_type }}</span>
                                        </td>
                                        <td class="text-truncate" style="max-width: 420px;">{{ item.content }}</td>
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
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';

const { t, locale } = useI18n();
const { showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const contextItems = ref([]);
const loading = ref(true);

function typeBadgeClass(type) {
    return {
        decision: 'bg-primary-transparent',
        constraint: 'bg-warning-transparent',
        summary: 'bg-info-transparent',
    }[type] ?? 'bg-secondary-transparent';
}

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

async function loadContextItems() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-project-context', { params: paginationParams.value });
        contextItems.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function onChangePage(target) {
    page.value = target;
    loadContextItems();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadContextItems();
}

onMounted(() => loadContextItems());
</script>
