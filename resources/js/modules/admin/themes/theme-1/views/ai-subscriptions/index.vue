<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_subscriptions.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_subscriptions.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_subscriptions.title') }}</li>
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
                                        <th scope="col">{{ t('ai_subscriptions.owner') }}</th>
                                        <th scope="col">{{ t('ai_subscriptions.plan') }}</th>
                                        <th scope="col">{{ t('ai_subscriptions.starts_at') }}</th>
                                        <th scope="col">{{ t('ai_subscriptions.ends_at') }}</th>
                                        <th scope="col">{{ t('ai_subscriptions.status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="5" />

                                    <tr v-else-if="!subscriptions.length">
                                        <td colspan="5" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_subscriptions.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="subscription in subscriptions" v-else :key="subscription.id">
                                        <td>
                                            <div>
                                                <span class="d-block fw-semibold">{{ subscription.owner?.name ?? '-' }}</span>
                                                <span class="d-block text-muted fs-11">
                                                    {{ t(`ai_subscriptions.${subscription.owner?.type}`) }} #{{ subscription.owner?.id }}
                                                </span>
                                            </div>
                                        </td>
                                        <td>{{ subscription.plan?.name ?? '-' }}</td>
                                        <td>{{ formatDate(subscription.starts_at) }}</td>
                                        <td>{{ subscription.ends_at ? formatDate(subscription.ends_at) : '-' }}</td>
                                        <td>
                                            <select
                                                class="form-select form-select-sm w-auto"
                                                :value="subscription.status"
                                                @change="changeStatus(subscription, $event.target.value)"
                                            >
                                                <option value="active">{{ t('ai_subscriptions.status_active') }}</option>
                                                <option value="inactive">{{ t('ai_subscriptions.status_inactive') }}</option>
                                                <option value="expired">{{ t('ai_subscriptions.status_expired') }}</option>
                                                <option value="cancelled">{{ t('ai_subscriptions.status_cancelled') }}</option>
                                                <option value="suspended">{{ t('ai_subscriptions.status_suspended') }}</option>
                                            </select>
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
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';

const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const subscriptions = ref([]);
const loading = ref(true);

function formatDate(value) {
    if (! value) return '-';
    return new Date(value).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

async function loadSubscriptions() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-subscriptions', { params: paginationParams.value });
        subscriptions.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function changeStatus(subscription, status) {
    const previous = subscription.status;
    subscription.status = status;

    try {
        const response = await adminAxios.put(`/api/admin/v1/ai-subscriptions/${subscription.id}`, { status });
        showSuccess(extractApiMessage(response, t('toast.status_changed')));
    } catch (error) {
        subscription.status = previous;
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function onChangePage(target) {
    page.value = target;
    loadSubscriptions();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadSubscriptions();
}

onMounted(() => loadSubscriptions());
</script>
