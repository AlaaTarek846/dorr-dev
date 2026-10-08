<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_security_events.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_security_events.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_security_events.title') }}</li>
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
                                        <th scope="col">{{ t('ai_security_events.owner') }}</th>
                                        <th scope="col">{{ t('ai_security_events.event_type') }}</th>
                                        <th scope="col">{{ t('ai_security_events.severity') }}</th>
                                        <th scope="col">{{ t('ai_security_events.correlation_id') }}</th>
                                        <th scope="col">{{ t('ai_security_events.description') }}</th>
                                        <th scope="col">{{ t('ai_security_events.created_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="6" />

                                    <tr v-else-if="!events.length">
                                        <td colspan="6" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_security_events.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="event in events" v-else :key="event.id">
                                        <td>
                                            <span class="d-block fw-semibold">{{ ownerDisplayName(event.owner) }}</span>
                                            <span class="d-block text-muted fs-11">#{{ event.owner?.id ?? '-' }}</span>
                                        </td>
                                        <td>{{ event.event_type }}</td>
                                        <td>
                                            <span class="badge" :class="severityBadgeClass(event.severity)">{{ event.severity }}</span>
                                        </td>
                                        <td class="text-muted">{{ event.correlation_id || '-' }}</td>
                                        <td class="text-muted">{{ event.description || '-' }}</td>
                                        <td>{{ formatDateTime(event.created_at) }}</td>
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
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

const { t, locale } = useI18n();
const { showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const events = ref([]);
const loading = ref(true);

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function severityBadgeClass(severity) {
    return {
        low: 'bg-success-transparent',
        medium: 'bg-warning-transparent',
        high: 'bg-danger-transparent',
        critical: 'bg-dark-transparent',
    }[severity] ?? 'bg-secondary-transparent';
}

async function loadEvents() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-security-events', { params: paginationParams.value });
        events.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function onChangePage(target) {
    page.value = target;
    loadEvents();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadEvents();
}

onMounted(() => loadEvents());
</script>
