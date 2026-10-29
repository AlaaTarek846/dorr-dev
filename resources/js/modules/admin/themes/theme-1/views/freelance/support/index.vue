<template>
    <div>
        <WalletPageHeader :title="t('freelance.support.title')" :section="t('sidebar.freelance')" :total="pagination.total ?? null" />

        <div class="alert alert-light border fs-13">{{ t('freelance.support.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <FlStatusFilter v-model="status" :options="statusOptions" />
                <div class="input-group input-group-sm catalog-toolbar-search">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('freelance.support.search')">
                    <button v-if="search" type="button" class="btn btn-light border" :title="t('users.clear_search')" @click="search = ''">
                        <i class="ri-close-line"></i>
                    </button>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.support.ticket') }}</th>
                                <th>{{ t('freelance.support.from') }}</th>
                                <th>{{ t('freelance.support.linked_to') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th>{{ t('support.last_message') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="['two-lines', 'two-lines', 'badge', 'badge', 'two-lines', 'actions']" />
                            <tr v-else-if="!rows.length">
                                <td colspan="6" class="border-0">
                                    <FlEmpty icon="ri-customer-service-2-line" :title="t('freelance.support.empty_title')" :text="t('freelance.support.empty')" />
                                </td>
                            </tr>
                            <tr v-for="row in rows" v-else :key="row.number">
                                <td class="ps-4">
                                    <span class="fw-semibold d-block text-truncate support-title">{{ row.title }}</span>
                                    <span class="text-muted fs-11">#{{ row.number }}</span>
                                </td>
                                <td>
                                    <span class="d-block">{{ row.user?.name || '—' }}</span>
                                    <span class="text-muted fs-11" dir="ltr">{{ row.user?.phone || '' }}</span>
                                </td>
                                <td>
                                    <span v-if="row.link" class="badge bg-info-transparent">
                                        <i :class="row.link.kind === 'project' ? 'ri-survey-line' : 'ri-scales-3-line'" class="me-1"></i>{{ t(`freelance.support.kind.${row.link.kind}`) }}
                                    </span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td><span class="badge" :class="statusBadge(row.status)">{{ t(`support.status.${row.status}`) }}</span></td>
                                <td class="fs-12">
                                    <span class="d-block text-truncate support-last">{{ row.last_message?.body || '—' }}</span>
                                    <span class="text-muted fs-11">{{ formatCatalogDate(row.last_message?.created_at, locale) }}</span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-list justify-content-end">
                                        <router-link v-if="can('support-tickets.view')" :to="{ name: 'admin.support-tickets.index', query: { ticket: row.number } }" class="btn btn-sm btn-info-light btn-icon" :title="t('support.open')">
                                            <i class="ri-chat-3-line"></i>
                                        </router-link>
                                        <button v-if="can('freelance-support.update')" type="button" class="btn btn-sm btn-primary-light btn-icon" :title="t('freelance.support.link')" @click="openLink(row)">
                                            <i class="ri-links-line"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" :per-page="perPage" @change="page = $event" @update:per-page="perPage = $event" />
        </div>

        <!-- Link a ticket to the work it is about, or clear the link. -->
        <WalletModal :show="!!linking" :title="t('freelance.support.link_title', { n: linking?.number })" size="md" @close="linking = null">
            <div v-if="linking">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button v-for="k in ['project', 'dispute', 'none']" :key="k" type="button" class="btn btn-sm" :class="form.kind === k ? 'btn-primary' : 'btn-outline-primary'" @click="form.kind = k">
                        {{ t(`freelance.support.kind.${k}`) }}
                    </button>
                </div>
                <template v-if="form.kind !== 'none'">
                    <label class="form-label fs-13">{{ t(`freelance.support.uuid_${form.kind}`) }}</label>
                    <input v-model.trim="form.uuid" type="text" class="form-control" dir="ltr" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    <div class="text-muted fs-11 mt-1">{{ t('freelance.support.uuid_hint') }}</div>
                </template>
                <div v-else class="text-muted fs-13">{{ t('freelance.support.clear_hint') }}</div>
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" @click="linking = null">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="working || !linkReady" @click="saveLink">
                    <span v-if="working" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import FlEmpty from '../../../../../../../components/freelance/FlEmpty.vue';
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { formatCatalogDate } from '../../../../../../../utils/catalog';

// Freelance on the support desk (FL-803): the freelancers' tickets and any ticket linked to a project or a dispute. Reading, assigning
// and replying stay on the Support page; here a ticket is linked to the work it is about. A link is only a label.
const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const endpoint = '/api/admin/v1/freelance-support/tickets';

const STATUSES = [['opened', 'pending'], ['reopened', 'pending'], ['resolved', 'active'], ['closed', 'inactive']];
const status = ref('all');
const search = ref('');
const page = ref(1);
const perPage = ref(15);
const rows = ref([]);
const pagination = ref({});
const counts = ref({});
const loading = ref(false);
const linking = ref(null);
const working = ref(false);
const form = reactive({ kind: 'project', uuid: '' });

const total = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0));
const statusOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), count: total.value, tone: 'all' },
    ...STATUSES.map(([s, tone]) => ({ value: s, label: t(`support.status.${s}`), count: counts.value[s] ?? 0, tone })),
]);
const statusBadge = (s) => ({ opened: 'bg-warning-transparent', reopened: 'bg-warning-transparent', resolved: 'bg-success-transparent', closed: 'bg-secondary-transparent' }[s] ?? 'bg-light text-dark');
const linkReady = computed(() => form.kind === 'none' || /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(form.uuid));

let timer;
watch([status, perPage], () => (page.value === 1 ? load() : (page.value = 1)));
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => (page.value === 1 ? load() : (page.value = 1)), 350);
});
watch(page, load);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(endpoint, { params: { page: page.value, per_page: perPage.value, status: status.value === 'all' ? undefined : status.value, q: search.value || undefined } });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
        counts.value = data.pagination?.counts ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function openLink(row) {
    Object.assign(form, { kind: row.link?.kind ?? 'project', uuid: row.link?.uuid ?? '' });
    linking.value = row;
}

async function saveLink() {
    working.value = true;
    try {
        const response = await adminAxios.post(`${endpoint}/${linking.value.number}/link`, { kind: form.kind, uuid: form.kind === 'none' ? undefined : form.uuid });
        showSuccess(extractApiMessage(response, t('toast.success')));
        linking.value = null;
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        working.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.support-title {
    max-width: 260px;
}

.support-last {
    max-width: 240px;
}
</style>
