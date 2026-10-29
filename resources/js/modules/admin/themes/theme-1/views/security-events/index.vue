<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('security.log.title') }}
                <span v-if="pagination?.total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">
                    {{ pagination.total }}
                </span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('security.log.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                        <div class="d-flex flex-wrap align-items-center gap-1 catalog-toolbar-filters">
                            <div class="input-group input-group-sm catalog-toolbar-search">
                                <span class="input-group-text bg-white">
                                    <i class="ri-search-line text-muted"></i>
                                </span>
                                <input
                                    v-model="search"
                                    type="search"
                                    class="form-control"
                                    :placeholder="t('security.log.search')"
                                >
                            </div>

                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="! warningsOnly ? 'catalog-filter-btn--all' : 'catalog-filter-btn--all-idle'"
                                @click="warningsOnly = false"
                            >
                                {{ t('security.log.filter_all') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm catalog-filter-btn"
                                :class="warningsOnly ? 'catalog-filter-btn--deleted' : 'catalog-filter-btn--deleted-idle'"
                                @click="warningsOnly = true"
                            >
                                <i class="ri-alert-line me-1"></i>{{ t('security.log.filter_warnings') }}
                            </button>

                            <select v-model="typeFilter" class="form-select form-select-sm w-auto">
                                <option value="">{{ t('security.log.all_types') }}</option>
                                <option v-for="type in types" :key="type" :value="type">{{ t(`security.events.${type}`) }}</option>
                            </select>

                            <span v-if="userFilter" class="badge bg-info-transparent d-inline-flex align-items-center gap-1 fs-12 py-2">
                                {{ t('security.log.customer') }} #{{ userFilter }}
                                <button type="button" class="btn btn-link p-0 text-info lh-1" :title="t('security.log.clear_customer')" @click="userFilter = null">
                                    <i class="ri-close-line"></i>
                                </button>
                            </span>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col" class="ps-4">{{ t('security.log.event') }}</th>
                                        <th scope="col">{{ t('security.log.customer') }}</th>
                                        <th scope="col">{{ t('security.log.device') }}</th>
                                        <th scope="col">{{ t('security.log.ip') }}</th>
                                        <th scope="col">{{ t('security.log.date') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="8" :columns="5" />

                                    <tr v-else-if="!events.length">
                                        <td colspan="5" class="border-0">
                                            <div class="text-center py-5">
                                                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                                                    <i class="ri-shield-keyhole-line fs-2 text-primary"></i>
                                                </span>
                                                <p class="fw-semibold mb-1">{{ t('security.log.empty_title') }}</p>
                                                <p class="text-muted mb-0">{{ t('security.log.empty') }}</p>
                                            </div>
                                        </td>
                                    </tr>

                                    <template v-else>
                                        <tr v-for="row in events" :key="row.id" class="crm-contact" role="button" @click="openShow(row.id)">
                                            <td class="ps-4">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span
                                                        class="avatar avatar-sm avatar-rounded"
                                                        :class="row.warning ? 'bg-danger-transparent' : 'bg-success-transparent'"
                                                    >
                                                        <i :class="row.warning ? 'ri-alert-line text-danger' : 'ri-shield-check-line text-success'"></i>
                                                    </span>
                                                    <div>
                                                        <span class="fw-semibold text-default">{{ row.label }}</span>
                                                        <span v-if="row.description" class="d-block text-muted fs-11">{{ row.description }}</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <template v-if="row.user">
                                                    <button type="button" class="btn btn-link p-0 fw-semibold text-default" @click.stop="userFilter = row.user.id">
                                                        <UserName :user="row.user">{{ row.user.name || `#${row.user.id}` }}</UserName>
                                                    </button>
                                                    <span class="d-block text-muted fs-11" dir="ltr">{{ row.user.phone }}</span>
                                                </template>
                                                <span v-else class="text-muted">—</span>
                                            </td>
                                            <td>
                                                <span class="d-block">{{ row.device_name || '—' }}</span>
                                                <span class="d-block text-muted fs-11">{{ row.platform_label }}</span>
                                            </td>
                                            <td>
                                                <span dir="ltr">{{ row.ip || '—' }}</span>
                                                <span v-if="row.country_code" class="badge bg-light text-default ms-1">{{ row.country_code }}</span>
                                            </td>
                                            <td>{{ formatCatalogDate(row.created_at, locale) }}</td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="pagination && !loading" class="card-footer border-top-0">
                        <div class="d-flex align-items-center flex-wrap gap-3">
                            <div class="text-muted fs-13">
                                {{ t('security.log.showing', { from: pagination.from ?? 0, to: pagination.to ?? 0, total: pagination.total ?? 0 }) }}
                            </div>

                            <div class="d-flex align-items-center gap-2 ms-md-auto">
                                <label class="text-muted fs-13 mb-0" for="security-per-page">{{ t('users.per_page') }}</label>
                                <select id="security-per-page" v-model.number="perPage" class="form-select form-select-sm w-auto">
                                    <option :value="20">20</option>
                                    <option :value="50">50</option>
                                    <option :value="100">100</option>
                                </select>
                            </div>

                            <nav aria-label="Security log pagination" class="pagination-style-4">
                                <ul class="pagination mb-0">
                                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                                        <button type="button" class="page-link" @click="load(pagination.current_page - 1)">{{ t('users.previous') }}</button>
                                    </li>
                                    <li v-for="page in pageNumbers" :key="page" class="page-item" :class="{ active: page === pagination.current_page }">
                                        <button type="button" class="page-link" @click="load(page)">{{ page }}</button>
                                    </li>
                                    <li class="page-item" :class="{ disabled: !pagination.next_page_url }">
                                        <button type="button" class="page-link text-primary" @click="load(pagination.current_page + 1)">{{ t('users.next') }}</button>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ModalShow :show="modalShow" :record="selected" @close="modalShow = false" @filter-user="userFilter = $event" />
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useOpenFromQuery from '../../../../../../composables/useOpenFromQuery';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';
import { formatCatalogDate } from '../../../../../../utils/catalog';
import ModalShow from './ModalShow.vue';
import UserName from '../../../../../../components/ui/UserName.vue';

const LIST_URI = '/api/admin/v1/security-events';

const types = [
    'login', 'login_new_device', 'login_failed', 'otp_requested', 'otp_failed', 'otp_locked', 'logout',
    'device_signed_out', 'other_devices_signed_out', 'session_limit', 'admin_signed_out', 'phone_changed',
    'pin_failed', 'pin_locked', 'pin_frozen', 'account_restored',
];

const { t, locale } = useI18n();
const route = useRoute();
const { showError } = useToast();

const events = ref([]);
const pagination = ref(null);
const loading = ref(false);
const perPage = ref(20);
const search = ref('');
const warningsOnly = ref(false);
const typeFilter = ref('');
// Reached from a customer's devices: `?user=ID` shows only that customer.
const userFilter = ref(route.query.user ? Number(route.query.user) : null);
const selected = ref(null);
const modalShow = ref(false);

const pageNumbers = computed(() => {
    const last = pagination.value?.last_page ?? 0;
    const current = pagination.value?.current_page ?? 1;
    let start = Math.max(1, current - 2);
    const end = Math.min(last, start + 4);
    start = Math.max(1, end - 4);

    return Array.from({ length: Math.max(0, end - start + 1) }, (_, i) => start + i);
});

async function load(page = 1) {
    if (pagination.value && (page < 1 || page > pagination.value.last_page)) {
        return;
    }

    loading.value = true;

    try {
        const { data } = await adminAxios.get(LIST_URI, {
            params: {
                page,
                per_page: perPage.value,
                search: search.value.trim() || undefined,
                warnings: warningsOnly.value ? 1 : undefined,
                type: typeFilter.value || undefined,
                user_id: userFilter.value || undefined,
            },
        });
        events.value = data.data ?? [];
        pagination.value = data.pagination ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

async function openShow(id) {
    const { data } = await adminAxios.get(`${LIST_URI}/${id}`);
    selected.value = data.data;
    modalShow.value = true;
}

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(1), 350);
});
watch([warningsOnly, typeFilter, userFilter, perPage], () => {
    pagination.value = null;
    load(1);
});
watch(() => route.query.user, (user) => {
    if (user) {
        userFilter.value = Number(user);
    }
});

// A notification can open one line of the log: `?event=ID`.
useOpenFromQuery('event', openShow);

onMounted(() => load(1));
</script>
