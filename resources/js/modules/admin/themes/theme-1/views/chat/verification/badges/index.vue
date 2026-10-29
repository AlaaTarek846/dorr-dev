<template>
    <div>
        <WalletPageHeader :title="t('chat.verification.badges.title')" :section="t('sidebar.chat')" :total="pagination?.total ?? null" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('chat.verification.badges.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm flex-wrap" role="group">
                    <button v-for="s in ['', ...statuses]" :key="s || 'all'" type="button" class="btn" :class="filters.status === s ? 'btn-primary' : 'btn-outline-light text-default'" @click="filters.status = s">
                        {{ s ? t(`chat.verification.badge_statuses.${s}`) : t('wallet.common.all_statuses') }}
                        <span v-if="s && counts[s]" class="badge bg-light text-default ms-1">{{ counts[s] }}</span>
                    </button>
                </div>
                <select v-model="filters.type" class="form-select form-select-sm ms-auto" style="max-width: 180px;">
                    <option value="">{{ t('chat.verification.badges.all_types') }}</option>
                    <option v-for="ty in ['standard', 'celebrity']" :key="ty" :value="ty">{{ t(`chat.verification.badge_types.${ty}`) }}</option>
                </select>
                <input v-model="filters.search" type="search" class="form-control form-control-sm" style="max-width: 220px;" :placeholder="t('search_placeholder')">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.verification.requests.account') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th>{{ t('chat.verification.badges.type') }}</th>
                                <th>{{ t('chat.verification.badges.since') }}</th>
                                <th>{{ t('chat.verification.badges.until') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.user?.id" role="button" @click="open(row.user?.id)">
                                <td class="ps-4">
                                    <div class="fw-semibold"><UserName :user="{ ...row.user, is_verified: row.is_verified }" /></div>
                                    <div class="fs-11 text-muted" dir="ltr">{{ row.user?.phone }}</div>
                                </td>
                                <td><span class="badge" :class="badgeClass(row.status)">{{ t(`chat.verification.badge_statuses.${row.status}`) }}</span></td>
                                <td>{{ t(`chat.verification.badge_types.${row.type}`) }}</td>
                                <td class="fs-12">{{ fmtDate(row.active_from) }}</td>
                                <td class="fs-12">{{ row.expires_at ? fmtDate(row.expires_at) : t('chat.verification.badges.no_end') }}</td>
                                <td class="text-end pe-4"><i class="ri-arrow-right-s-line text-muted rtl-flip"></i></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <WalletModal :show="!!detail" :title="detail ? (detail.user?.name || detail.user?.phone) : ''" size="lg" @close="detail = null">
            <template v-if="detail">
                <div v-if="detail.badge" class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <span class="badge fs-12" :class="badgeClass(detail.badge.status)">{{ t(`chat.verification.badge_statuses.${detail.badge.status}`) }}</span>
                    <span class="badge bg-light text-default">{{ t(`chat.verification.badge_types.${detail.badge.type}`) }}</span>
                    <span class="fs-12 text-muted">{{ t('chat.verification.badges.until') }}: {{ detail.badge.expires_at ? fmtDate(detail.badge.expires_at) : t('chat.verification.badges.no_end') }}</span>
                </div>
                <div v-if="detail.badge?.reason" class="alert alert-light border fs-13">{{ detail.badge.reason }}</div>

                <div v-if="detail.actions.length" class="d-flex flex-wrap gap-2 mb-3">
                    <button v-for="a in detail.actions" :key="a" type="button" class="btn btn-sm" :class="a === 'withdraw' ? 'btn-outline-danger' : a === 'suspend' ? 'btn-outline-warning' : 'btn-primary-light'" :disabled="busy" @click="startAction(a)">
                        {{ t(`chat.verification.badges.action_${a}`) }}
                    </button>
                </div>

                <form v-if="action" id="vb-action-form" class="border rounded p-3 mb-3" novalidate @submit.prevent="submit">
                    <p class="fs-13 text-muted mb-2">{{ t(`chat.verification.badges.confirm_${action}`) }}</p>
                    <template v-if="action === 'change'">
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label fs-13">{{ t('chat.verification.badges.type') }}</label>
                                <select v-model="form.type" class="form-select form-select-sm">
                                    <option v-for="ty in ['standard', 'celebrity']" :key="ty" :value="ty">{{ t(`chat.verification.badge_types.${ty}`) }}</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fs-13">{{ t('chat.verification.badges.until') }}</label>
                                <input v-model="form.expires_at" type="datetime-local" class="form-control form-control-sm" :class="{ 'is-invalid': errors.expires_at }">
                                <div v-if="errors.expires_at" class="text-danger fs-12">{{ errors.expires_at }}</div>
                            </div>
                        </div>
                    </template>
                    <label class="form-label fs-13">{{ t('chat.verification.audit.reason') }} <span class="text-danger">*</span></label>
                    <textarea v-model="form.reason" rows="2" class="form-control" :class="{ 'is-invalid': errors.reason }"></textarea>
                    <div class="fs-11 text-muted mt-1">{{ t('chat.verification.requests.reason_hint') }}</div>
                    <div v-if="errors.reason || errors.general" class="text-danger fs-12 mt-1">{{ errors.reason || errors.general }}</div>
                    <div class="d-flex justify-content-end gap-2 mt-2">
                        <button type="button" class="btn btn-light btn-sm" @click="action = null">{{ t('cancel') }}</button>
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="busy"><span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
                    </div>
                </form>

                <h6 class="fs-13 fw-semibold">{{ t('chat.verification.requests.history') }}</h6>
                <ul class="list-unstyled mb-0">
                    <li v-for="(h, i) in [...detail.history].reverse()" :key="i" class="mb-2 fs-13">
                        <span class="badge me-1" :class="badgeClass(h.to)">{{ t(`chat.verification.badge_statuses.${h.to}`) }}</span>
                        <span class="fs-11 text-muted">{{ fmtDate(h.at) }} · {{ t(`chat.verification.audit.actor_${h.actor_type}`) }}</span>
                        <div v-if="h.reason" class="fs-12 text-wrap">{{ reasonText(h.reason) }}</div>
                    </li>
                </ul>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../../api/adminAxios';
import UserName from '../../../../../../../../components/ui/UserName.vue';
import WalletModal from '../../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../../composables/useWalletList';

// Every account's verification badge (docs/verification_tasks.md AV-023, AV-025): hold, bring back, change, withdraw —
// each with a reason the account reads. The buttons come from the server (`actions`), checked again on press.
const { t, te } = useI18n();
const { showSuccess, showError } = useToast();

const statuses = ['active', 'temporary', 'suspended', 'expired', 'withdrawn'];
const { rows, loading, pagination, filters, fetch } = useWalletList('chat-verification/badges', { defaults: { status: '', type: '', search: '' }, perPage: 20 });
const counts = computed(() => pagination.value?.counts ?? {});
const detail = ref(null);
const userId = ref(null);
const action = ref(null);
const busy = ref(false);
const form = reactive({ reason: '', type: 'standard', expires_at: '' });
const errors = reactive({});

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—');
const badgeClass = (s) => ({ active: 'bg-success', temporary: 'bg-info-transparent', suspended: 'bg-warning-transparent', expired: 'bg-secondary-transparent', withdrawn: 'bg-danger-transparent' }[s] ?? 'bg-light');
const reasonText = (r) => (te(`chat.verification.badges.reason_${r.split(':')[0]}`) ? t(`chat.verification.badges.reason_${r.split(':')[0]}`) : r);
const toLocalInput = (iso) => (iso ? new Date(new Date(iso).getTime() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16) : '');

async function open(id) {
    if (!id) return;
    userId.value = id;
    action.value = null;
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/chat-verification/badges/${id}`);
        detail.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function startAction(name) {
    Object.keys(errors).forEach((k) => delete errors[k]);
    Object.assign(form, { reason: '', type: detail.value?.badge?.type ?? 'standard', expires_at: toLocalInput(detail.value?.badge?.expires_at) });
    action.value = name;
}

async function submit() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    busy.value = true;
    try {
        const body = { reason: form.reason };
        if (action.value === 'change') {
            body.type = form.type;
            body.expires_at = form.expires_at ? new Date(form.expires_at).toISOString() : null;
        }
        const response = await adminAxios.post(`/api/admin/v1/chat-verification/badges/${userId.value}/${action.value}`, body);
        detail.value = response.data.data;
        action.value = null;
        showSuccess(extractApiMessage(response, t('toast.success')));
        fetch();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { errors[key] ??= messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error, t('toast.error'));
    } finally {
        busy.value = false;
    }
}

onMounted(() => fetch(1));
</script>
