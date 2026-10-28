<template>
    <div>
        <WalletPageHeader :title="c?.number || t('rides.cases.title')" :section="t('rides.cases.title')" />
        <div v-if="!c" class="text-center py-5"><span class="spinner-border"></span></div>

        <template v-else>
            <div class="card custom-card mb-3">
                <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                    <span class="badge fs-13" :class="statusClass(c.status)">{{ c.status_label }}</span>
                    <span class="badge bg-light text-dark">{{ c.kind_label }} · {{ c.category_label }}</span>
                    <span class="badge" :class="c.priority === 'urgent' ? 'bg-danger' : c.priority === 'high' ? 'bg-warning-transparent' : 'bg-light text-dark'">{{ t(`rides.cases.priorities.${c.priority}`) }}</span>
                    <router-link v-if="c.ride_id" :to="{ name: 'admin.rides.rides.show', params: { id: c.ride_id } }" class="ms-2">{{ c.ride?.number }}</router-link>
                    <div v-if="c.disputed_minor != null" class="ms-auto fs-4 fw-bold">{{ money(c.disputed_minor) }}</div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-xl-5">
                    <WalletSection :title="t('rides.cases.about')" icon="ri-user-voice-line" class="mb-3">
                        <WalletInfoTile :label="t('rides.cases.reporter')" icon="ri-user-line" class="mb-2">
                            <span class="badge me-1" :class="c.reporter.type === 'driver' ? 'bg-info-transparent' : 'bg-primary-transparent'">{{ t(`rides.safety.reporter.${c.reporter.type}`) }}</span>
                            {{ c.reporter.name || '—' }} <a v-if="c.reporter.phone" :href="`tel:${c.reporter.phone}`" class="text-muted fs-12" dir="ltr">{{ c.reporter.phone }}</a>
                        </WalletInfoTile>
                        <WalletInfoTile :label="t('rides.cases.summary')" icon="ri-chat-quote-line">{{ c.summary }}</WalletInfoTile>
                        <div v-if="c.appealed_at" class="alert alert-warning mt-3 mb-0 fs-13">
                            <strong>{{ t('rides.cases.appealed') }}</strong> · {{ formatDate(c.appealed_at) }}<div>{{ c.appeal_note }}</div>
                        </div>
                    </WalletSection>

                    <!-- AI-12: DORR AI's first reading, for the team only — a suggestion, the decision stays ours. -->
                    <WalletSection :title="t('rides.cases.ai_title')" icon="ri-sparkling-2-line" class="mb-3">
                        <template v-if="c.ai_triage">
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <span v-if="c.ai_triage.priority" class="badge" :class="{ urgent: 'bg-danger', high: 'bg-warning', normal: 'bg-info-transparent', low: 'bg-light text-dark' }[c.ai_triage.priority]">{{ t('rides.cases.ai_priority') }}: {{ t('rides.cases.ai_priorities.' + c.ai_triage.priority) }}</span>
                                <span v-if="c.ai_triage.route" class="badge bg-primary-transparent">{{ t('rides.cases.ai_route') }}: {{ t('rides.cases.ai_routes.' + c.ai_triage.route) }}</span>
                                <span v-if="c.ai_triage.category" class="badge bg-secondary-transparent">{{ c.ai_triage.category }}</span>
                            </div>
                            <p v-if="c.ai_triage.summary" class="fs-13 mb-2">{{ c.ai_triage.summary }}</p>
                            <ul v-if="c.ai_triage.points?.length" class="fs-12 text-muted mb-2 ps-3">
                                <li v-for="(p, i) in c.ai_triage.points" :key="i">{{ p }}</li>
                            </ul>
                            <div class="fs-11 text-muted">{{ t('rides.cases.ai_note') }} · {{ formatDate(c.ai_triage.at) }}</div>
                        </template>
                        <div v-else class="text-muted fs-13">{{ t('rides.cases.ai_none') }}</div>
                        <button v-if="canUpdate" type="button" class="btn btn-sm btn-outline-primary mt-2" :disabled="busy" @click="triage">
                            <i class="ri-sparkling-2-line me-1"></i>{{ t(c.ai_triage ? 'rides.cases.ai_again' : 'rides.cases.ai_read') }}
                        </button>
                    </WalletSection>

                    <WalletSection v-if="c.evidence" :title="t('rides.cases.evidence')" icon="ri-map-pin-time-line" class="mb-3">
                        <div class="fs-12 text-muted mb-2">{{ t('rides.cases.evidence_frozen', { at: formatDate(c.evidence.captured_at) }) }}</div>
                        <div v-for="(e, i) in c.evidence.timeline" :key="i" class="d-flex align-items-center gap-2 fs-13 mb-1">
                            <span class="badge bg-light text-dark">{{ t(`rides.cases.events.${e.event}`, e.event) }}</span>
                            <span>{{ formatDate(e.at) }}</span>
                            <span v-if="e.distance_to_pickup_m != null" class="text-muted fs-12">{{ t('rides.cases.from_pickup', { m: e.distance_to_pickup_m }) }}</span>
                            <a v-if="e.lat" :href="`https://www.google.com/maps/search/?api=1&query=${e.lat},${e.lng}`" target="_blank" rel="noopener" class="ms-auto"><i class="ri-map-pin-2-line"></i></a>
                        </div>
                        <div class="fs-13 mt-2">
                            {{ t('rides.cases.waiting') }}: {{ Math.round((c.evidence.waiting.pickup_s || 0) / 60) }} {{ t('rides.cases.min') }}
                            <span class="text-muted">({{ t('rides.cases.free') }} {{ Math.round((c.evidence.waiting.free_s || 0) / 60) }})</span>
                        </div>
                        <div class="fs-13">
                            {{ t('rides.cases.route') }}: {{ km(c.evidence.route.driven_m) }} / {{ km(c.evidence.route.planned_m) }} km
                            <span v-if="c.evidence.route.points" class="text-muted">· {{ c.evidence.route.points }} {{ t('rides.cases.points') }}</span>
                            <span v-if="c.evidence.route.gps_gap_s" class="text-danger">· GPS gap {{ c.evidence.route.gps_gap_s }}s</span>
                        </div>
                        <table v-if="c.evidence.lines.length || c.evidence.adjustments.length" class="table table-sm mt-2 mb-0 fs-12">
                            <tbody>
                                <tr v-for="l in c.evidence.lines" :key="'l' + l.code"><td>{{ l.code }}</td><td class="text-muted">{{ l.visible_to }}</td><td class="text-end">{{ money(l.amount_minor) }}</td></tr>
                                <tr v-for="(a, i) in c.evidence.adjustments" :key="'a' + i"><td>{{ a.type }}</td><td class="text-muted">{{ a.affects }}</td><td class="text-end">{{ money(a.amount_minor) }}</td></tr>
                            </tbody>
                        </table>
                    </WalletSection>

                    <WalletSection v-if="c.files.length" :title="t('rides.cases.files')" icon="ri-attachment-2" class="mb-3">
                        <div v-for="f in c.files" :key="f.id" class="fs-13 mb-1"><a :href="f.url" target="_blank" rel="noopener"><i class="ri-file-line me-1"></i>{{ f.name }}</a> <span class="text-muted fs-11">· {{ f.mine ? '' : '' }}{{ Math.round(f.size / 1024) }} KB</span></div>
                    </WalletSection>
                </div>

                <div class="col-xl-7">
                    <WalletSection :title="t('rides.cases.decision')" icon="ri-scales-3-line" class="mb-3">
                        <div v-if="c.decision" class="mb-3">
                            <span class="badge" :class="statusClass(c.status)">{{ c.status_label }}</span>
                            <div class="mt-2">{{ c.decision.reason }}</div>
                            <div v-if="c.decision.refund_minor" class="fs-13 mt-1">
                                {{ t('rides.cases.refund') }}: {{ money(c.decision.refund_minor) }}
                                <span class="badge" :class="c.decision.refund_applied ? 'bg-success-transparent' : 'bg-warning-transparent'">{{ c.decision.refund_applied ? t('rides.cases.refund_paid') : t('rides.cases.refund_not_paid') }}</span>
                            </div>
                            <div class="fs-12 text-muted mt-1">{{ c.decided_by }} · {{ formatDate(c.decision.decided_at) }}</div>
                        </div>
                        <template v-if="canUpdate && !c.decided">
                            <div class="d-flex gap-2 mb-3">
                                <button type="button" class="btn btn-sm btn-outline-info" :disabled="busy" @click="setStatus('in_review')"><i class="ri-eye-line me-1"></i>{{ t('rides.cases.take') }}</button>
                                <button type="button" class="btn btn-sm btn-outline-warning" :disabled="busy" @click="setStatus('needs_info')"><i class="ri-question-line me-1"></i>{{ t('rides.cases.ask_info') }}</button>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label fs-13">{{ t('rides.cases.outcome') }}</label>
                                    <select v-model="form.outcome" class="form-select">
                                        <option v-for="o in ['confirmed', 'partially_confirmed', 'rejected']" :key="o" :value="o">{{ t(`rides.cases.status.${o}`) }}</option>
                                    </select>
                                </div>
                                <div v-if="c.reporter.type === 'user' && c.ride_id" class="col-md-7">
                                    <label class="form-label fs-13">{{ t('rides.cases.refund') }}</label>
                                    <input v-model="form.refund" type="number" min="0" step="0.01" class="form-control" placeholder="0.00">
                                    <div class="form-check mt-1">
                                        <input id="apply" v-model="form.apply" type="checkbox" class="form-check-input">
                                        <label for="apply" class="form-check-label fs-12">{{ t('rides.cases.refund_apply') }}</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fs-13">{{ t('rides.cases.reason') }}</label>
                                    <textarea v-model="form.reason" class="form-control" rows="3" maxlength="1000"></textarea>
                                    <div class="form-text">{{ t('rides.cases.reason_hint') }}</div>
                                </div>
                            </div>
                            <div class="text-end mt-3">
                                <button type="button" class="btn btn-primary" :disabled="busy || form.reason.length < 3" @click="decide"><i class="ri-check-double-line me-1"></i>{{ t('rides.cases.decide') }}</button>
                            </div>
                        </template>
                    </WalletSection>

                    <WalletSection :title="t('rides.cases.conversation')" icon="ri-chat-3-line" class="mb-3">
                        <div v-if="!c.messages.length" class="text-muted fs-13">{{ t('rides.cases.no_messages') }}</div>
                        <div v-for="m in c.messages" :key="m.id" class="mb-2 p-2 rounded fs-13" :class="m.internal ? 'bg-warning-transparent' : m.from === 'support' ? 'bg-primary-transparent' : 'bg-light'">
                            <div class="fs-11 text-muted">
                                {{ m.from === 'support' ? t('rides.cases.team') : t('rides.safety.reporter.' + (m.from === 'me' ? c.reporter.type : m.from)) }} · {{ formatDate(m.at) }}
                                <span v-if="m.internal" class="badge bg-warning ms-1">{{ t('rides.cases.private') }}</span>
                            </div>
                            {{ m.body }}
                        </div>
                        <template v-if="canUpdate">
                            <textarea v-model="reply" class="form-control mt-2" rows="2" maxlength="2000" :placeholder="t('rides.cases.reply')"></textarea>
                            <div class="d-flex align-items-center gap-3 mt-2">
                                <div class="form-check mb-0">
                                    <input id="internal" v-model="internal" type="checkbox" class="form-check-input">
                                    <label for="internal" class="form-check-label fs-12">{{ t('rides.cases.private_note') }}</label>
                                </div>
                                <label class="btn btn-sm btn-outline-secondary mb-0 ms-auto">
                                    <i class="ri-attachment-2 me-1"></i>{{ t('rides.cases.attach') }}
                                    <input type="file" class="d-none" accept="image/*,application/pdf" @change="upload">
                                </label>
                                <button type="button" class="btn btn-sm btn-primary" :disabled="busy || !reply.trim()" @click="send">{{ t('rides.cases.send') }}</button>
                            </div>
                        </template>
                    </WalletSection>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletInfoTile from '../../../../../../../components/wallet/WalletInfoTile.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletSection from '../../../../../../../components/wallet/WalletSection.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// One case: who and what, the evidence the system froze when it was opened, the conversation (with private
// notes), and the decision — an outcome, the reason the person is shown, and an optional refund that is only
// paid when the box is ticked. Everything reloads from the server's answer after each action.
const { t, locale } = useI18n();
const { showError, showSuccess } = useToast();
const { can } = usePermission();
const canUpdate = computed(() => can('ride-cases.update'));
const route = useRoute();
const c = ref(null);
const busy = ref(false);
const reply = ref('');
const internal = ref(false);
const form = reactive({ outcome: 'confirmed', reason: '', refund: '', apply: false });
const base = () => `/api/admin/v1/ride-cases/${route.params.id}`;
const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' }) : '');
const places = () => c.value?.currency?.decimal_places ?? 2;
const money = (minor) => `${((minor ?? 0) / 10 ** places()).toFixed(places())} ${c.value?.currency?.symbol ?? ''}`;
const km = (m) => (m == null ? '—' : (m / 1000).toFixed(1));
const statusClass = (s) => ({ submitted: 'bg-primary-transparent', in_review: 'bg-info-transparent', needs_info: 'bg-warning-transparent', confirmed: 'bg-success-transparent', partially_confirmed: 'bg-success-transparent', rejected: 'bg-secondary-transparent' })[s];

async function run(action, ok) {
    busy.value = true;
    try {
        const { data } = await action();
        c.value = data.data;
        if (ok) showSuccess(ok);
        return true;
    } catch (error) {
        showError(extractApiErrorMessage(error));
        return false;
    } finally {
        busy.value = false;
    }
}

const setStatus = (status) => run(() => adminAxios.post(`${base()}/status`, { status }));
const triage = () => run(() => adminAxios.post(`${base()}/ai-triage`));

async function send() {
    if (await run(() => adminAxios.post(`${base()}/messages`, { body: reply.value, internal: internal.value }))) {
        reply.value = '';
        internal.value = false;
    }
}

async function upload(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    const body = new FormData();
    body.append('file', file);
    await run(() => adminAxios.post(`${base()}/files`, body));
}

async function decide() {
    const refund = Math.round(Number(form.refund || 0) * 10 ** places());
    await run(() => adminAxios.post(`${base()}/decide`, { outcome: form.outcome, reason: form.reason, refund_minor: refund || null, apply_refund: refund > 0 && form.apply }), t('rides.cases.decided_ok'));
}

onMounted(async () => {
    try {
        const { data } = await adminAxios.get(base());
        c.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>
