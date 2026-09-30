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

        <div class="row g-3 mb-3" v-if="overview">
            <div class="col-xl-3 col-sm-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <span class="d-block text-muted fs-12">{{ t('ai_subscriptions.overview_mrr') }}</span>
                        <span class="d-block fw-semibold fs-20">{{ overview.mrr }}</span>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <span class="d-block text-muted fs-12">{{ t('ai_subscriptions.overview_revenue_month') }}</span>
                        <span class="d-block fw-semibold fs-20">{{ overview.revenue_this_month }}</span>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <span class="d-block text-muted fs-12">{{ t('ai_subscriptions.overview_paid_active') }}</span>
                        <span class="d-block fw-semibold fs-20">{{ overview.paid_active }}</span>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <span class="d-block text-muted fs-12">{{ t('ai_subscriptions.overview_churn') }}</span>
                        <span class="d-block fw-semibold fs-20">{{ overview.churn_rate_30d_percent }}%</span>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <span class="d-block text-muted fs-12">{{ t('ai_subscriptions.overview_trial') }}</span>
                        <span class="d-block fw-semibold fs-20">{{ overview.trial_subscribers }}</span>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <span class="d-block text-muted fs-12">{{ t('ai_subscriptions.overview_in_grace') }}</span>
                        <span class="d-block fw-semibold fs-20">{{ overview.in_grace_period }}</span>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <span class="d-block text-muted fs-12">{{ t('ai_subscriptions.overview_suspended') }}</span>
                        <span class="d-block fw-semibold fs-20">{{ overview.suspended }}</span>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <span class="d-block text-muted fs-12 mb-1">{{ t('ai_subscriptions.plan_distribution') }}</span>
                        <div v-for="row in overview.plan_distribution" :key="row.plan_id" class="d-flex justify-content-between fs-12">
                            <span class="text-muted">{{ row.plan_name }}</span>
                            <span class="fw-semibold">{{ row.active_subscribers }}</span>
                        </div>
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
                                        <th scope="col">{{ t('ai_subscriptions.owner') }}</th>
                                        <th scope="col">{{ t('ai_subscriptions.plan') }}</th>
                                        <th scope="col">{{ t('ai_subscriptions.starts_at') }}</th>
                                        <th scope="col">{{ t('ai_subscriptions.ends_at') }}</th>
                                        <th scope="col">{{ t('ai_subscriptions.auto_renew') }}</th>
                                        <th scope="col">{{ t('ai_subscriptions.status') }}</th>
                                        <th scope="col">{{ t('ai_subscriptions.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="7" />

                                    <tr v-else-if="!subscriptions.length">
                                        <td colspan="7" class="border-0">
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
                                            <span class="badge" :class="subscription.auto_renew ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                {{ subscription.auto_renew ? t('ai_subscriptions.auto_renew_on') : t('ai_subscriptions.auto_renew_off') }}
                                            </span>
                                            <span v-if="subscription.in_grace_period" class="badge bg-warning-transparent ms-1">
                                                {{ t('ai_subscriptions.in_grace_period') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge" :class="statusBadgeClass(subscription.status)">
                                                {{ t(`ai_subscriptions.status_${subscription.status}`) }}
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-1">
                                                <input
                                                    v-model.number="extendDays[subscription.id]"
                                                    type="number"
                                                    min="1"
                                                    class="form-control form-control-sm"
                                                    style="width: 70px"
                                                    :placeholder="t('ai_subscriptions.extend_days_placeholder')"
                                                >
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-primary-light"
                                                    :disabled="busyId === subscription.id"
                                                    @click="extend(subscription)"
                                                >
                                                    {{ t('ai_subscriptions.extend') }}
                                                </button>
                                                <button
                                                    v-if="subscription.status === 'active'"
                                                    type="button"
                                                    class="btn btn-sm btn-warning-light"
                                                    :disabled="busyId === subscription.id"
                                                    @click="suspend(subscription)"
                                                >
                                                    {{ t('ai_subscriptions.suspend') }}
                                                </button>
                                                <button
                                                    v-if="subscription.status === 'suspended'"
                                                    type="button"
                                                    class="btn btn-sm btn-success-light"
                                                    :disabled="busyId === subscription.id"
                                                    @click="reactivate(subscription)"
                                                >
                                                    {{ t('ai_subscriptions.reactivate') }}
                                                </button>
                                                <button
                                                    v-if="['active', 'suspended'].includes(subscription.status)"
                                                    type="button"
                                                    class="btn btn-sm btn-danger-light"
                                                    :disabled="busyId === subscription.id"
                                                    @click="cancelSubscription(subscription)"
                                                >
                                                    {{ t('ai_subscriptions.cancel') }}
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
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const subscriptions = ref([]);
const loading = ref(true);
const overview = ref(null);
const busyId = ref(null);
const extendDays = reactive({});

function formatDate(value) {
    if (! value) return '-';
    return new Date(value).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function statusBadgeClass(status) {
    return {
        active: 'bg-success-transparent',
        inactive: 'bg-secondary-transparent',
        expired: 'bg-secondary-transparent',
        cancelled: 'bg-secondary-transparent',
        suspended: 'bg-danger-transparent',
    }[status] ?? 'bg-secondary-transparent';
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

async function loadOverview() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-subscriptions/overview');
        overview.value = data.data ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function runAction(subscription, url, successMessage, payload = {}) {
    busyId.value = subscription.id;

    try {
        const response = await adminAxios.post(url, payload);
        const updated = response.data?.data;

        if (updated) {
            const index = subscriptions.value.findIndex((s) => s.id === subscription.id);
            if (index !== -1) subscriptions.value[index] = updated;
        }

        showSuccess(extractApiMessage(response, successMessage));
        loadOverview();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        busyId.value = null;
    }
}

function extend(subscription) {
    const days = extendDays[subscription.id];

    if (! days || days < 1) {
        showWarning(t('toast.validation_error'));
        return;
    }

    runAction(subscription, `/api/admin/v1/ai-subscriptions/${subscription.id}/extend`, t('toast.updated'), { days });
}

function suspend(subscription) {
    if (! window.confirm(t('ai_subscriptions.confirm_suspend'))) return;
    runAction(subscription, `/api/admin/v1/ai-subscriptions/${subscription.id}/suspend`, t('toast.updated'));
}

function reactivate(subscription) {
    if (! window.confirm(t('ai_subscriptions.confirm_reactivate'))) return;
    runAction(subscription, `/api/admin/v1/ai-subscriptions/${subscription.id}/reactivate`, t('toast.updated'));
}

function cancelSubscription(subscription) {
    if (! window.confirm(t('ai_subscriptions.confirm_cancel'))) return;
    runAction(subscription, `/api/admin/v1/ai-subscriptions/${subscription.id}/cancel`, t('toast.updated'));
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

onMounted(() => {
    loadSubscriptions();
    loadOverview();
});
</script>
