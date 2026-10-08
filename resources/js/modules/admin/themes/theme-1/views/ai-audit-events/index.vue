<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_audit_events.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_audit_events.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_audit_events.title') }}</li>
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
                                        <th scope="col">{{ t('ai_audit_events.event_type') }}</th>
                                        <th scope="col">{{ t('ai_audit_events.severity') }}</th>
                                        <th scope="col">{{ t('ai_audit_events.owner') }}</th>
                                        <th scope="col">{{ t('ai_audit_events.actor') }}</th>
                                        <th scope="col">{{ t('ai_audit_events.subject') }}</th>
                                        <th scope="col">{{ t('ai_audit_events.trace_id') }}</th>
                                        <th scope="col">{{ t('ai_audit_events.ip_address') }}</th>
                                        <th scope="col">{{ t('ai_audit_events.created_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="8" />

                                    <tr v-else-if="!events.length">
                                        <td colspan="8" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_audit_events.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="event in events" v-else :key="event.id">
                                        <td class="fw-semibold">{{ event.event_type }}</td>
                                        <td>
                                            <span class="badge" :class="severityBadgeClass(event.severity)">{{ event.severity }}</span>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ ownerDisplayName(event.owner) }}</span>
                                            <span v-if="event.owner" class="d-block text-muted fs-11">#{{ event.owner.id }}</span>
                                        </td>
                                        <td>
                                            <span class="d-block">{{ event.actor?.name || event.actor?.type || '-' }}</span>
                                            <span v-if="event.actor" class="d-block text-muted fs-11">#{{ event.actor.id }}</span>
                                        </td>
                                        <td>
                                            <span v-if="event.subject" class="d-block">{{ event.subject.type }} #{{ event.subject.id }}</span>
                                            <span v-else>-</span>
                                        </td>
                                        <td class="text-muted">{{ event.trace_id || '-' }}</td>
                                        <td class="text-muted">{{ event.ip_address || '-' }}</td>
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
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

const { t, locale } = useI18n();
const { showError } = useToast();

const events = ref([]);
const loading = ref(true);

const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function severityBadgeClass(severity) {
    if (severity === 'high' || severity === 'critical') return 'bg-danger-transparent';
    if (severity === 'medium') return 'bg-warning-transparent';
    return 'bg-secondary-transparent';
}

async function loadEvents() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-audit-events', { params: paginationParams.value });
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
