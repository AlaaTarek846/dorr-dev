<template>
    <div>
        <WalletPageHeader :title="t('chat.verification.audit.title')" :section="t('sidebar.chat')" :total="active.pagination.value?.total ?? null" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('chat.verification.audit.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm" role="group">
                    <button v-for="tab in ['decisions', 'notifications']" :key="tab" type="button" class="btn" :class="current === tab ? 'btn-primary' : 'btn-outline-light text-default'" @click="current = tab">
                        {{ t(`chat.verification.audit.tab_${tab}`) }}
                    </button>
                </div>

                <template v-if="current === 'decisions'">
                    <select v-model="decisions.filters.action" class="form-select form-select-sm ms-auto" style="max-width: 240px;">
                        <option value="">{{ t('chat.verification.audit.all_actions') }}</option>
                        <option v-for="a in actions" :key="a" :value="a">{{ actionLabel(a) }}</option>
                    </select>
                    <input v-model="decisions.filters.from" type="date" class="form-control form-control-sm" style="max-width: 160px;" :title="t('chat.verification.audit.from')">
                    <input v-model="decisions.filters.to" type="date" class="form-control form-control-sm" style="max-width: 160px;" :title="t('chat.verification.audit.to')">
                </template>
                <template v-else>
                    <select v-model="notifications.filters.audience" class="form-select form-select-sm ms-auto" style="max-width: 180px;">
                        <option value="">{{ t('chat.verification.audit.all_audiences') }}</option>
                        <option value="user">{{ t('chat.verification.audit.audience_user') }}</option>
                        <option value="admin">{{ t('chat.verification.audit.audience_admin') }}</option>
                    </select>
                    <select v-model="notifications.filters.status" class="form-select form-select-sm" style="max-width: 160px;">
                        <option value="">{{ t('wallet.common.all_statuses') }}</option>
                        <option value="sent">{{ t('chat.verification.audit.status_sent') }}</option>
                        <option value="failed">{{ t('chat.verification.audit.status_failed') }}</option>
                    </select>
                </template>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table v-if="current === 'decisions'" class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('wallet.common.date') }}</th>
                                <th>{{ t('chat.verification.audit.action') }}</th>
                                <th>{{ t('chat.verification.audit.actor') }}</th>
                                <th>{{ t('chat.verification.audit.subject') }}</th>
                                <th>{{ t('chat.verification.audit.change') }}</th>
                                <th class="pe-4">{{ t('chat.verification.audit.reason') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="decisions.loading.value"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!decisions.rows.value.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in decisions.rows.value" v-else :key="row.id" :class="{ 'table-danger': row.action === 'denied' }">
                                <td class="ps-4 fs-12">{{ fmtDate(row.created_at) }}</td>
                                <td class="fw-semibold">{{ actionLabel(row.action) }}</td>
                                <td class="fs-13">{{ row.actor?.name || t(`chat.verification.audit.actor_${row.actor?.type || 'system'}`) }}<div v-if="row.ip" class="fs-11 text-muted" dir="ltr">{{ row.ip }}</div></td>
                                <td class="fs-12">{{ row.subject_type ? `${row.subject_type} #${row.subject_id}` : '—' }}</td>
                                <td class="fs-11 text-wrap" style="min-width: 260px; max-width: 420px;">
                                    <div v-for="line in changes(row)" :key="line.key"><code>{{ line.key }}</code>: <span class="text-muted">{{ line.before }}</span> → <span class="fw-semibold">{{ line.after }}</span></div>
                                </td>
                                <td class="pe-4 fs-12 text-wrap" style="min-width: 160px;">{{ row.reason || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <table v-else class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('wallet.common.date') }}</th>
                                <th>{{ t('chat.verification.audit.event') }}</th>
                                <th>{{ t('chat.verification.audit.audience') }}</th>
                                <th>{{ t('chat.verification.audit.recipient') }}</th>
                                <th>{{ t('chat.verification.audit.subject') }}</th>
                                <th class="pe-4">{{ t('wallet.common.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="notifications.loading.value"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!notifications.rows.value.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in notifications.rows.value" v-else :key="row.id">
                                <td class="ps-4 fs-12">{{ fmtDate(row.created_at) }}</td>
                                <td class="fw-semibold"><code class="fs-12">{{ row.event }}</code></td>
                                <td><span class="badge" :class="row.audience === 'admin' ? 'bg-info-transparent' : 'bg-primary-transparent'">{{ t(`chat.verification.audit.audience_${row.audience}`) }}</span><i v-if="row.push" class="ri-smartphone-line ms-1 text-muted" :title="t('chat.verification.audit.push')"></i></td>
                                <td class="fs-12">{{ row.recipient_type }} #{{ row.recipient_id }}</td>
                                <td class="fs-12">{{ row.subject_type ? `${row.subject_type} #${row.subject_id}` : '—' }}</td>
                                <td class="pe-4">
                                    <span class="badge" :class="row.status === 'sent' ? 'bg-success-transparent' : 'bg-danger-transparent'">{{ t(`chat.verification.audit.status_${row.status}`) }}</span>
                                    <div v-if="row.error" class="fs-11 text-danger text-wrap">{{ row.error }}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="active.pagination.value" @change="active.fetch" />
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import WalletPageHeader from '../../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../../components/wallet/WalletPagination.vue';
import useWalletList from '../../../../../../../../composables/useWalletList';

// Account verification's trail (docs/verification_tasks.md AV-005 / AV-006): the sensitive decisions with their
// before, after and reason — refused attempts in red — and every notification the events sent.
const { t, te } = useI18n();

const current = ref('decisions');
const decisions = useWalletList('chat-verification/audit', { defaults: { action: '', from: '', to: '' }, perPage: 20 });
const notifications = useWalletList('chat-verification/notifications', { defaults: { audience: '', status: '' }, perPage: 20 });
const active = computed(() => (current.value === 'decisions' ? decisions : notifications));
const actions = computed(() => decisions.pagination.value?.actions ?? []);

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
const actionLabel = (action) => (te(`chat.verification.audit.actions.${action}`) ? t(`chat.verification.audit.actions.${action}`) : action);
const show = (value) => (value === null || value === undefined ? '—' : typeof value === 'object' ? JSON.stringify(value) : String(value));

/** One line per field that changed (or was set, or removed). */
function changes(row) {
    const before = row.before ?? {};
    const after = row.after ?? {};
    return [...new Set([...Object.keys(before), ...Object.keys(after)])]
        .filter((key) => JSON.stringify(before[key]) !== JSON.stringify(after[key]))
        .map((key) => ({ key, before: show(before[key]), after: show(after[key]) }));
}

watch(current, (tab) => (tab === 'decisions' ? decisions : notifications).fetch(1));
onMounted(() => decisions.fetch(1));
</script>
