<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_sites.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_sites.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_sites.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center py-3">
                <input v-model="filters.search" type="search" class="form-control form-control-sm w-auto" :placeholder="t('ai_sites.search')" @keyup.enter="reload">
                <select v-model="filters.status" class="form-select form-select-sm w-auto" @change="reload">
                    <option value="">{{ t('ai_sites.all_statuses') }}</option>
                    <option v-for="s in ['generating', 'ready', 'failed']" :key="s" :value="s">{{ t('ai_sites.status_' + s) }}</option>
                </select>
                <select v-model="filters.access_type" class="form-select form-select-sm w-auto" @change="reload">
                    <option value="">{{ t('ai_sites.all_access') }}</option>
                    <option value="plan">{{ t('ai_sites.access_plan') }}</option>
                    <option value="purchase">{{ t('ai_sites.access_purchase') }}</option>
                </select>
                <div class="form-check ms-2">
                    <input id="only-disabled" v-model="filters.disabled" type="checkbox" class="form-check-input" @change="reload">
                    <label class="form-check-label" for="only-disabled">{{ t('ai_sites.only_disabled') }}</label>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ t('ai_sites.site') }}</th>
                                <th>{{ t('ai_sites.owner') }}</th>
                                <th>{{ t('ai_sites.access') }}</th>
                                <th>{{ t('ai_sites.status') }}</th>
                                <th>{{ t('ai_sites.created_at') }}</th>
                                <th class="text-end pe-4">{{ t('ai_sites.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="5" :columns="6" />

                            <tr v-else-if="!sites.length">
                                <td colspan="6" class="border-0">
                                    <div class="text-center py-5 text-muted">{{ t('ai_sites.empty') }}</div>
                                </td>
                            </tr>

                            <tr v-for="site in sites" v-else :key="site.id">
                                <td>
                                    <div class="fw-semibold">{{ site.title }}</div>
                                    <div v-if="site.last_error" class="text-danger fs-12">{{ site.last_error }}</div>
                                </td>
                                <td>{{ site.owner?.name ?? '-' }} <span class="text-muted fs-12">#{{ site.owner?.id }}</span></td>
                                <td>
                                    <span class="badge" :class="site.access_type === 'purchase' ? 'bg-warning-transparent' : 'bg-primary-transparent'">
                                        {{ t('ai_sites.access_' + site.access_type) }}
                                    </span>
                                </td>
                                <td>
                                    <span v-if="site.is_disabled" class="badge bg-danger-transparent">{{ t('ai_sites.disabled') }}</span>
                                    <span v-else class="badge" :class="statusClass(site.status)">{{ t('ai_sites.status_' + site.status) }}</span>
                                </td>
                                <td>{{ formatDate(site.created_at) }}</td>
                                <td class="text-end pe-4">
                                    <div class="btn-list justify-content-end">
                                        <a
                                            v-if="site.preview_url"
                                            :href="site.preview_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="btn btn-sm btn-primary-light"
                                        >
                                            <i class="ri-external-link-line me-1 align-middle"></i>{{ t('ai_sites.open') }}
                                        </a>
                                        <button
                                            v-if="site.is_disabled"
                                            type="button"
                                            class="btn btn-sm btn-success-light"
                                            @click="act(site, 'enable')"
                                        >
                                            {{ t('ai_sites.enable') }}
                                        </button>
                                        <button v-else type="button" class="btn btn-sm btn-warning-light" @click="act(site, 'disable')">
                                            {{ t('ai_sites.disable') }}
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(site)">
                                            <i class="ri-delete-bin-line"></i>
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

const sites = ref([]);
const loading = ref(true);
const filters = reactive({ search: '', status: '', access_type: '', disabled: false });

function statusClass(status) {
    return { ready: 'bg-success-transparent', generating: 'bg-info-transparent', failed: 'bg-danger-transparent' }[status] ?? 'bg-secondary-transparent';
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '-';
}

async function load() {
    loading.value = true;

    try {
        const params = { ...paginationParams.value };

        Object.entries(filters).forEach(([key, value]) => {
            if (value) {
                params[key] = value === true ? 1 : value;
            }
        });

        const { data } = await adminAxios.get('/api/admin/v1/ai-sites', { params });
        sites.value = data.data ?? [];
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

async function act(site, action) {
    try {
        const response = await adminAxios.post(`/api/admin/v1/ai-sites/${site.id}/${action}`);
        showSuccess(extractApiMessage(response, t('toast.success')));
        await load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function remove(site) {
    if (! window.confirm(t('ai_sites.confirm_delete'))) {
        return;
    }

    try {
        const response = await adminAxios.delete(`/api/admin/v1/ai-sites/${site.id}`);
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
