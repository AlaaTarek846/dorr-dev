<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_knowledge_sources.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_knowledge_sources.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0 d-flex align-items-center gap-2">
                <button type="button" class="btn btn-primary btn-sm" @click="showForm = !showForm">
                    <i class="ri-add-line me-1"></i>{{ t('ai_knowledge_sources.add') }}
                </button>
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_knowledge_sources.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div v-if="showForm" class="row">
            <div class="col-xl-12">
                <div class="card custom-card mb-4">
                    <div class="card-body">
                        <form @submit.prevent="submitSource">
                            <div class="row gy-3">
                                <div class="col-md-6">
                                    <label class="form-label">{{ t('ai_knowledge_sources.name') }}</label>
                                    <input v-model="form.name" type="text" class="form-control" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">{{ t('ai_knowledge_sources.domain') }}</label>
                                    <input v-model="form.domain" type="text" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">{{ t('ai_knowledge_sources.country_code') }}</label>
                                    <input v-model="form.country_code" type="text" maxlength="2" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ t('ai_knowledge_sources.publisher') }}</label>
                                    <input v-model="form.publisher" type="text" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ t('ai_knowledge_sources.authority') }}</label>
                                    <input v-model="form.authority" type="text" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ t('ai_knowledge_sources.data_classification') }}</label>
                                    <select v-model="form.data_classification" class="form-select">
                                        <option value="public">public</option>
                                        <option value="internal">internal</option>
                                        <option value="confidential">confidential</option>
                                        <option value="personal">personal</option>
                                        <option value="secret">secret</option>
                                        <option value="unverified">unverified</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">{{ t('ai_knowledge_sources.content') }}</label>
                                    <textarea v-model="form.content" rows="6" class="form-control" required></textarea>
                                </div>
                            </div>
                            <div class="mt-3 d-flex gap-2">
                                <button type="submit" class="btn btn-primary" :disabled="submitting">
                                    {{ submitting ? t('saving') : t('save') }}
                                </button>
                                <button type="button" class="btn btn-light" @click="showForm = false">{{ t('cancel') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
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
                                        <th scope="col">{{ t('ai_knowledge_sources.name') }}</th>
                                        <th scope="col">{{ t('ai_knowledge_sources.domain') }}</th>
                                        <th scope="col">{{ t('ai_knowledge_sources.data_classification') }}</th>
                                        <th scope="col">{{ t('ai_knowledge_sources.version') }}</th>
                                        <th scope="col">{{ t('ai_knowledge_sources.approval_status') }}</th>
                                        <th scope="col">{{ t('ai_knowledge_sources.created_at') }}</th>
                                        <th scope="col" class="text-end">{{ t('actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="7" />

                                    <tr v-else-if="!sources.length">
                                        <td colspan="7" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_knowledge_sources.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="source in sources" v-else :key="source.id">
                                        <td>{{ source.name }}</td>
                                        <td>{{ source.domain || '-' }}</td>
                                        <td>
                                            <span class="badge" :class="classificationBadgeClass(source.data_classification)">{{ source.data_classification }}</span>
                                        </td>
                                        <td>
                                            v{{ source.current_version }}
                                            <span v-if="source.change_detected" class="badge bg-warning-transparent ms-1">{{ t('ai_knowledge_sources.change_detected') }}</span>
                                        </td>
                                        <td>
                                            <span class="badge" :class="approvalBadgeClass(source.approval_status)">
                                                {{ t('ai_knowledge_sources.approval_' + source.approval_status) }}
                                            </span>
                                        </td>
                                        <td>{{ formatDateTime(source.created_at) }}</td>
                                        <td class="text-end">
                                            <div class="d-flex gap-1 justify-content-end">
                                                <button
                                                    v-if="source.approval_status !== 'approved'"
                                                    type="button"
                                                    class="btn btn-sm btn-success-light"
                                                    :disabled="actingId === source.id"
                                                    @click="act(source, 'approve')"
                                                >
                                                    {{ t('ai_knowledge_sources.approve') }}
                                                </button>
                                                <button
                                                    v-if="source.approval_status !== 'rejected'"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light"
                                                    :disabled="actingId === source.id"
                                                    @click="act(source, 'reject')"
                                                >
                                                    {{ t('ai_knowledge_sources.reject') }}
                                                </button>
                                                <button
                                                    v-if="source.approval_status !== 'deprecated'"
                                                    type="button"
                                                    class="btn btn-sm btn-secondary-light"
                                                    :disabled="actingId === source.id"
                                                    @click="act(source, 'deprecate')"
                                                >
                                                    {{ t('ai_knowledge_sources.deprecate') }}
                                                </button>
                                            </div>
                                        </td>
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
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';

const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const sources = ref([]);
const loading = ref(true);
const showForm = ref(false);
const submitting = ref(false);
const actingId = ref(null);

const form = reactive({
    name: '',
    domain: '',
    country_code: '',
    publisher: '',
    authority: '',
    data_classification: 'internal',
    content: '',
});

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function classificationBadgeClass(classification) {
    return {
        public: 'bg-success-transparent',
        internal: 'bg-secondary-transparent',
        confidential: 'bg-warning-transparent',
        personal: 'bg-info-transparent',
        secret: 'bg-danger-transparent',
        unverified: 'bg-warning-transparent',
    }[classification] ?? 'bg-secondary-transparent';
}

function approvalBadgeClass(status) {
    return {
        pending: 'bg-warning-transparent',
        approved: 'bg-success-transparent',
        rejected: 'bg-danger-transparent',
        deprecated: 'bg-secondary-transparent',
    }[status] ?? 'bg-secondary-transparent';
}

async function loadSources() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-knowledge-sources', { params: paginationParams.value });
        sources.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function submitSource() {
    submitting.value = true;

    try {
        await adminAxios.post('/api/admin/v1/ai-knowledge-sources', form);
        showSuccess(t('ai_knowledge_sources.ingested'));
        showForm.value = false;
        form.name = '';
        form.domain = '';
        form.country_code = '';
        form.publisher = '';
        form.authority = '';
        form.data_classification = 'internal';
        form.content = '';
        await loadSources();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        submitting.value = false;
    }
}

async function act(source, action) {
    actingId.value = source.id;

    try {
        await adminAxios.post(`/api/admin/v1/ai-knowledge-sources/${source.id}/${action}`);
        await loadSources();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        actingId.value = null;
    }
}

function onChangePage(target) {
    page.value = target;
    loadSources();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadSources();
}

onMounted(() => loadSources());
</script>
