<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_site_requests.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_site_requests.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0 d-flex align-items-center gap-2">
                <button type="button" class="btn btn-light btn-sm" @click="statusesShow = true">
                    <i class="ri-palette-line me-1 align-middle"></i>{{ t('ai_site_requests.manage_statuses') }}
                </button>
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_site_requests.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card" :class="{ 'border-warning': settings && ! settings.is_enabled }">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3 flex-wrap">
                    <span class="avatar avatar-md bg-primary-transparent"><i class="ri-customer-service-2-line fs-20"></i></span>
                    <div class="flex-fill">
                        <p class="fw-semibold mb-1">{{ t('ai_site_requests.enabled') }}</p>
                        <p class="text-muted fs-13 mb-0">{{ t('ai_site_requests.enabled_hint') }}</p>
                    </div>
                    <div v-if="settings" class="toggle toggle-success mb-0" :class="{ on: settings.is_enabled }" role="button" tabindex="0" @click="toggleEnabled" @keydown.enter.space.prevent="toggleEnabled"><span></span></div>
                </div>

                <div v-if="settings" class="row g-3 mt-1">
                    <div class="col-md-3">
                        <label class="form-label fs-13">{{ t('ai_site_requests.quote_valid_days') }}</label>
                        <input v-model.number="settings.quote_valid_days" type="number" min="1" max="90" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fs-13">{{ t('ai_site_requests.intro_ar') }}</label>
                        <textarea v-model="settings.intro_ar" rows="2" class="form-control form-control-sm" dir="rtl"></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fs-13">{{ t('ai_site_requests.intro_en') }}</label>
                        <textarea v-model="settings.intro_en" rows="2" class="form-control form-control-sm" dir="ltr"></textarea>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="button" class="btn btn-primary btn-sm w-100" :disabled="savingSettings" @click="saveSettings">{{ t('ai_site_offers.save') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center py-3">
                <button type="button" class="btn btn-sm" :class="filters.status === '' ? 'btn-primary' : 'btn-primary-light'" @click="setStatus('')">
                    {{ t('ai_site_requests.all') }} <span class="badge bg-light text-default ms-1">{{ total }}</span>
                </button>
                <button
                    v-for="s in statuses"
                    :key="s.code"
                    type="button"
                    class="btn btn-sm"
                    :class="filters.status === s.code ? 'btn-primary' : 'btn-primary-light'"
                    @click="setStatus(s.code)"
                >
                    {{ locale === 'ar' ? s.name_ar : s.name_en }} <span class="badge bg-light text-default ms-1">{{ counts[s.code] ?? 0 }}</span>
                </button>
                <input v-model="filters.search" type="search" class="form-control form-control-sm w-auto ms-auto" :placeholder="t('ai_site_requests.search')" @keyup.enter="reload">
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ t('ai_site_requests.reference') }}</th>
                                <th>{{ t('ai_site_requests.project') }}</th>
                                <th>{{ t('ai_site_requests.customer') }}</th>
                                <th>{{ t('ai_site_requests.status') }}</th>
                                <th>{{ t('ai_site_requests.quote') }}</th>
                                <th>{{ t('ai_site_requests.due') }}</th>
                                <th>{{ t('ai_site_requests.created') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="5" :columns="7" />
                            <tr v-else-if="! requests.length">
                                <td colspan="7" class="border-0 text-center py-5 text-muted">{{ t('ai_site_requests.empty') }}</td>
                            </tr>
                            <tr v-for="r in requests" v-else :key="r.id" role="button" @click="open(r)">
                                <td>
                                    <span class="badge bg-primary-transparent">{{ r.reference }}</span>
                                    <span v-if="r.unread" class="badge bg-danger ms-1">{{ r.unread }}</span>
                                </td>
                                <td class="fw-semibold">{{ r.title }}</td>
                                <td>{{ r.owner?.name || '#' + r.owner?.id }}</td>
                                <td><span class="badge" :class="`bg-${r.status.color}-transparent`">{{ r.status.name }}</span></td>
                                <td>{{ r.quote ? `${r.quote.amount} ${r.currency ?? ''}` : '-' }}</td>
                                <td>{{ r.timeline.due_at ? formatDate(r.timeline.due_at) : '-' }}</td>
                                <td>{{ formatDate(r.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <AdminPaginationFooter :pagination="pagination" :current-page="page" :per-page="perPage" :loading="loading" @change-page="onChangePage" @change-per-page="onChangePerPage" />
        </div>

        <ModalRequestStatuses :show="statusesShow" @close="statusesShow = false" @saved="loadSummary" />
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import adminAxios from '../../../../../../api/adminAxios';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import ModalRequestStatuses from './ModalRequestStatuses.vue';

const { t, locale } = useI18n();
const router = useRouter();
const { showSuccess, showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const requests = ref([]);
const loading = ref(true);
const statuses = ref([]);
const counts = ref({});
const settings = ref(null);
const savingSettings = ref(false);
const statusesShow = ref(false);
const filters = reactive({ search: '', status: '' });
const total = computed(() => Object.values(counts.value).reduce((sum, n) => sum + Number(n), 0));

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '-';
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
        const { data } = await adminAxios.get('/api/admin/v1/ai-site-requests', { params });
        requests.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function loadSummary() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-site-requests/summary');
        counts.value = data.data?.counts ?? {};
        statuses.value = (data.data?.statuses ?? []).filter((s) => s.is_active);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function loadSettings() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-site-requests/settings');
        settings.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function saveSettings() {
    savingSettings.value = true;

    try {
        const response = await adminAxios.put('/api/admin/v1/ai-site-requests/settings', settings.value);
        settings.value = response.data.data;
        showSuccess(extractApiMessage(response, t('toast.success')));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        savingSettings.value = false;
    }
}

function toggleEnabled() {
    settings.value.is_enabled = ! settings.value.is_enabled;
    saveSettings();
}

function setStatus(code) {
    filters.status = code;
    reload();
}

function reload() {
    page.value = 1;
    load();
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

function open(request) {
    router.push({ name: 'admin.ai-site-requests.show', params: { id: request.id } });
}

onMounted(() => {
    load();
    loadSummary();
    loadSettings();
});
</script>
