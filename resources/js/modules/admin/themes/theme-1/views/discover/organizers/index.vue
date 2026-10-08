<template>
    <div>
        <WalletPageHeader :title="t('discover.organizers.title')" :section="t('sidebar.discover')" :total="meta.total || null" />

        <div class="alert alert-info fs-13">{{ t('discover.organizers.intro') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <ul class="nav nav-pills nav-style-3 gap-1">
                    <li v-for="s in tabs" :key="s" class="nav-item">
                        <button type="button" class="nav-link py-1 px-3" :class="{ active: filters.status === s }" @click="filters.status = s; load(1)">
                            {{ s ? t(`discover.organizers.s_${s}`) : t('discover.review.all') }}
                            <span v-if="s === 'pending' && pendingCount" class="badge bg-warning ms-1">{{ pendingCount }}</span>
                        </button>
                    </li>
                </ul>
                <div class="input-group input-group-sm ms-auto" style="max-width: 240px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('discover.organizers.search')" @keyup.enter="load(1)">
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('discover.organizers.name') }}</th>
                                <th>{{ t('discover.organizers.account') }}</th>
                                <th>{{ t('discover.organizers.contact') }}</th>
                                <th>{{ t('discover.organizers.events') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id">
                                    <td class="ps-4">
                                        <div class="fw-semibold">{{ row.name }} <i v-if="row.status === 'verified'" class="ri-verified-badge-fill text-primary"></i></div>
                                        <div v-if="row.about" class="text-muted fs-12 text-truncate" style="max-width: 280px;" :title="row.about">{{ row.about }}</div>
                                    </td>
                                    <td>
                                        <div class="fs-13">{{ row.account?.name || '—' }}</div>
                                        <div class="text-muted fs-12" dir="ltr">{{ row.account?.phone }}</div>
                                    </td>
                                    <td class="fs-12">
                                        <div v-if="row.website"><a :href="row.website" target="_blank" rel="noopener" dir="ltr">{{ row.website }}</a></div>
                                        <div v-if="row.email" dir="ltr">{{ row.email }}</div>
                                        <div v-if="row.phone" dir="ltr">{{ row.phone }}</div>
                                    </td>
                                    <td>
                                        <router-link :to="{ name: 'admin.discover.events' }" class="badge bg-info-transparent">{{ row.events_count }}</router-link>
                                    </td>
                                    <td>
                                        <span class="badge" :class="badge(row.status)">{{ t(`discover.organizers.s_${row.status}`) }}</span>
                                        <div v-if="row.review_note" class="text-muted fs-11 text-truncate" style="max-width: 160px;" :title="row.review_note">{{ row.review_note }}</div>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div v-if="canUpdate" class="btn-list justify-content-end">
                                            <button v-if="row.status !== 'verified'" type="button" class="btn btn-sm btn-success-light" @click="review(row, 'verified')"><i class="ri-verified-badge-line me-1"></i>{{ t('discover.organizers.verify') }}</button>
                                            <button v-if="row.status === 'pending'" type="button" class="btn btn-sm btn-danger-light" @click="review(row, 'rejected')">{{ t('discover.organizers.reject') }}</button>
                                            <button v-if="row.status === 'verified'" type="button" class="btn btn-sm btn-warning-light" @click="review(row, 'suspended')">{{ t('discover.organizers.suspend') }}</button>
                                            <button v-if="row.status === 'suspended' || row.status === 'rejected'" type="button" class="btn btn-sm btn-light" @click="review(row, 'pending')">{{ t('discover.organizers.reopen') }}</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="meta.last_page > 1" class="card-footer d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-sm btn-light" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)"><i class="ri-arrow-left-s-line"></i></button>
                <span class="align-self-center fs-13">{{ meta.current_page }} / {{ meta.last_page }}</span>
                <button type="button" class="btn btn-sm btn-light" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)"><i class="ri-arrow-right-s-line"></i></button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const canUpdate = computed(() => can('discover-organizers.update'));

const tabs = ['pending', 'verified', 'rejected', 'suspended', ''];
const rows = ref([]);
const meta = ref({});
const pendingCount = ref(0);
const loading = ref(false);
const filters = reactive({ status: 'pending', search: '' });

function badge(s) {
    return { pending: 'bg-warning', verified: 'bg-success', rejected: 'bg-danger', suspended: 'bg-secondary' }[s] || 'bg-light';
}

async function review(row, status) {
    let note = null;
    if (status === 'rejected' || status === 'suspended') {
        note = window.prompt(t('discover.organizers.note_prompt'));
        if (note === null) return;
    }
    try {
        await adminAxios.patch(`/api/admin/v1/discover-organizers/${row.id}/review`, { status, note: note || null });
        showSuccess(t('discover.organizers.saved'));
        load(meta.value.current_page || 1);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load(page = 1) {
    loading.value = true;
    try {
        const params = { page, per_page: 15 };
        if (filters.status) params.status = filters.status;
        if (filters.search) params.search = filters.search;
        const { data } = await adminAxios.get('/api/admin/v1/discover-organizers', { params });
        rows.value = data.data ?? [];
        meta.value = data.pagination ?? {};
        pendingCount.value = data.pending_count ?? 0;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

onMounted(() => load(1));
</script>
