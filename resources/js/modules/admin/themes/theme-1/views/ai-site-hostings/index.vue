<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_site_hosting.hostings_title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_site_hosting.hostings_subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_site_hosting.hostings_title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center py-3">
                <input v-model="filters.search" type="search" class="form-control form-control-sm w-auto" :placeholder="t('ai_site_hosting.search')" @keyup.enter="reload">
                <select v-model="filters.status" class="form-select form-select-sm w-auto" @change="reload">
                    <option value="">{{ t('ai_sites.all_statuses') }}</option>
                    <option v-for="s in ['active', 'grace', 'cancelled', 'suspended']" :key="s" :value="s">{{ t('ai_site_hosting.status_' + s) }}</option>
                </select>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ t('ai_site_hosting.address') }}</th>
                                <th>{{ t('ai_sites.owner') }}</th>
                                <th>{{ t('ai_site_hosting.plan') }}</th>
                                <th>{{ t('ai_sites.status') }}</th>
                                <th>{{ t('ai_site_hosting.ends_at') }}</th>
                                <th class="text-end pe-4">{{ t('ai_sites.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="5" :columns="6" />

                            <tr v-else-if="!items.length">
                                <td colspan="6" class="border-0"><div class="text-center py-5 text-muted">{{ t('ai_site_hosting.hostings_empty') }}</div></td>
                            </tr>

                            <tr v-for="item in items" v-else :key="item.id">
                                <td>
                                    <a :href="item.url" target="_blank" rel="noopener noreferrer" class="fw-semibold" dir="ltr">{{ item.subdomain }}</a>
                                    <div class="text-muted fs-12">{{ item.project_title }}</div>
                                </td>
                                <td>{{ item.owner?.name ?? '-' }} <span class="text-muted fs-12">#{{ item.owner?.id }}</span></td>
                                <td>
                                    {{ item.plan?.name }}
                                    <div class="text-muted fs-12">{{ item.amount }} {{ item.currency }} / {{ t('ai_site_hosting.period_' + item.period) }}</div>
                                </td>
                                <td>
                                    <span class="badge" :class="statusClass(item)">{{ item.is_live ? t('ai_site_hosting.status_' + item.status) : (item.admin_suspended ? t('ai_site_hosting.admin_suspended') : t('ai_site_hosting.offline')) }}</span>
                                    <span v-if="!item.auto_renew" class="badge bg-secondary-transparent ms-1">{{ t('ai_site_hosting.no_auto_renew') }}</span>
                                </td>
                                <td>{{ formatDate(item.ends_at) }}</td>
                                <td class="text-end pe-4">
                                    <div class="btn-list justify-content-end">
                                        <button v-if="item.is_live" type="button" class="btn btn-sm btn-warning-light" @click="act(item, 'suspend')">{{ t('ai_sites.disable') }}</button>
                                        <button v-else-if="item.admin_suspended" type="button" class="btn btn-sm btn-success-light" @click="act(item, 'resume')">{{ t('ai_sites.enable') }}</button>
                                        <button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(item)"><i class="ri-delete-bin-line"></i></button>
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
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

const { t } = useI18n();
const { showSuccess, showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const items = ref([]);
const loading = ref(true);
const filters = reactive({ search: '', status: '' });

function statusClass(item) {
    if (! item.is_live) return 'bg-danger-transparent';

    return { active: 'bg-success-transparent', grace: 'bg-warning-transparent', cancelled: 'bg-info-transparent' }[item.status] ?? 'bg-secondary-transparent';
}

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString() : '-';
}

async function load() {
    loading.value = true;

    try {
        const params = { ...paginationParams.value };

        Object.entries(filters).forEach(([key, value]) => {
            if (value) {
                params[key] = value;
            }
        });

        const { data } = await adminAxios.get('/api/admin/v1/ai-site-hostings', { params });
        items.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function reload() {
    page.value = 1;
    load();
}

async function act(item, action) {
    try {
        const response = await adminAxios.post(`/api/admin/v1/ai-site-hostings/${item.id}/${action}`);
        showSuccess(extractApiMessage(response, t('toast.success')));
        await load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function remove(item) {
    if (! window.confirm(t('ai_site_hosting.confirm_delete_hosting'))) {
        return;
    }

    try {
        const response = await adminAxios.delete(`/api/admin/v1/ai-site-hostings/${item.id}`);
        showSuccess(extractApiMessage(response, t('toast.deleted')));
        await load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function onChangePage(target) {
    page.value = target;
    load();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    load();
}

onMounted(load);
</script>
