<template>
    <div>
        <WalletPageHeader :title="t('rides.ranks.title')" :section="t('sidebar.rides')" />
        <p class="text-muted fs-13 mb-3">{{ t('rides.ranks.hint') }}</p>

        <ul class="nav nav-tabs mb-3">
            <li v-for="tab in ['ranks', 'appeals', 'driver', 'payouts']" :key="tab" class="nav-item">
                <a href="#" class="nav-link" :class="{ active: current === tab }" @click.prevent="current = tab">
                    {{ t(`rides.ranks.tabs.${tab}`) }}<span v-if="tab === 'appeals' && appeals.length" class="badge bg-danger ms-1">{{ appeals.length }}</span>
                </a>
            </li>
        </ul>

        <!-- The ranks and their thresholds. -->
        <div v-if="current === 'ranks'" class="card custom-card">
            <div class="card-header d-flex align-items-center py-3">
                <h6 class="mb-0">{{ t('rides.ranks.tabs.ranks') }}</h6>
                <button v-if="can('driver-ranks.create')" type="button" class="btn btn-primary btn-sm ms-auto" @click="open(null)"><i class="ri-add-line me-1"></i>{{ t('rides.lists.add') }}</button>
            </div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle fs-13">
                    <thead><tr><th>{{ t('rides.ranks.name') }}</th><th>{{ t('rides.ranks.m.trips') }}</th><th>{{ t('rides.ranks.m.rating') }}</th><th>{{ t('rides.ranks.m.cleanliness') }}</th><th>{{ t('rides.ranks.m.commitment') }}</th><th>{{ t('rides.ranks.m.completion') }}</th><th>{{ t('rides.ranks.m.cancel') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="r in ranks" :key="r.id" :class="{ 'opacity-50': !r.status }">
                            <td><span class="d-inline-block rounded-circle me-1" :style="{ width: '10px', height: '10px', background: r.color || '#999' }"></span><b>{{ word(r.name) }}</b> <span v-if="r.probation" class="badge bg-light text-dark">{{ t('rides.ranks.probation') }}</span></td>
                            <td>{{ r.min_trips }}</td><td>{{ (r.min_rating / 100).toFixed(2) }}</td><td>{{ (r.min_cleanliness / 100).toFixed(2) }}</td>
                            <td>{{ r.min_commitment_bp / 100 }}%</td><td>{{ r.min_completion_bp / 100 }}%</td><td>≤ {{ r.max_cancel_bp / 100 }}%</td>
                            <td class="text-end">
                                <button v-if="can('driver-ranks.update')" type="button" class="btn btn-sm btn-light btn-icon me-1" @click="open(r)"><i class="ri-edit-line"></i></button>
                                <button v-if="can('driver-ranks.delete')" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(r)"><i class="ri-delete-bin-line"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Appeals waiting for an answer. -->
        <div v-else-if="current === 'appeals'" class="card custom-card">
            <div class="card-body">
                <div v-if="!appeals.length" class="text-muted text-center py-4">{{ t('wallet.common.empty') }}</div>
                <div v-for="a in appeals" :key="a.id" class="border rounded p-3 mb-2">
                    <div class="d-flex"><b>{{ a.driver?.name }}</b><span class="text-muted ms-2">{{ a.driver?.phone }}</span><span class="ms-auto badge bg-light text-dark">{{ t(`rides.ranks.kinds.${a.kind}`) }}</span></div>
                    <div class="fw-semibold mt-1">{{ a.title }}</div>
                    <div v-if="a.body" class="fs-12 text-muted">{{ a.body }}</div>
                    <div class="mt-2 p-2 bg-light rounded fs-13"><i class="ri-chat-quote-line me-1"></i>{{ a.appeal_text }}</div>
                    <div v-if="can('driver-ranks.update')" class="d-flex gap-2 mt-2">
                        <input v-model="answers[a.id]" class="form-control form-control-sm" :placeholder="t('rides.ranks.answer')">
                        <button type="button" class="btn btn-sm btn-success" @click="answer(a, true)">{{ t('rides.ranks.accept') }}</button>
                        <button type="button" class="btn btn-sm btn-danger" @click="answer(a, false)">{{ t('rides.ranks.reject') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- D-224, D-299: international payout requests and frozen wallets. -->
        <div v-else-if="current === 'payouts'" class="card custom-card">
            <div class="card-body">
                <div v-if="!payouts.length" class="text-muted text-center py-4">{{ t('wallet.common.empty') }}</div>
                <div v-for="p in payouts" :key="p.driver?.id" class="border rounded p-3 mb-2">
                    <div class="d-flex"><b>{{ p.driver?.name }}</b><span class="text-muted ms-2">{{ p.driver?.phone }}</span></div>
                    <div v-if="p.international_status === 'pending'" class="mt-2">
                        <div class="fs-13"><span class="badge bg-info me-1">{{ t('rides.ranks.payouts.intl') }}</span>{{ p.international_reason }}</div>
                        <div v-if="can('driver-ranks.update')" class="d-flex gap-2 mt-2">
                            <input v-model="answers['i' + p.driver.id]" class="form-control form-control-sm" :placeholder="t('rides.ranks.answer')">
                            <button type="button" class="btn btn-sm btn-success" @click="intl(p, true)">{{ t('rides.ranks.accept') }}</button>
                            <button type="button" class="btn btn-sm btn-danger" @click="intl(p, false)">{{ t('rides.ranks.reject') }}</button>
                        </div>
                    </div>
                    <div v-if="p.frozen_at" class="mt-2 d-flex align-items-center">
                        <span class="badge bg-danger me-2">{{ t('rides.ranks.payouts.frozen') }}</span><span class="fs-12 text-muted">{{ p.frozen_at.slice(0, 16).replace('T', ' ') }} · {{ p.frozen_reason }}</span>
                        <button v-if="can('driver-ranks.update')" type="button" class="btn btn-sm btn-outline-success ms-auto" @click="unfreeze(p)">{{ t('rides.ranks.payouts.unfreeze') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- One captain: rank, numbers, record, and writing a note / warning / praise. -->
        <div v-else class="card custom-card">
            <div class="card-body">
                <div class="d-flex gap-2 mb-3" style="max-width: 460px">
                    <input v-model="phone" class="form-control" :placeholder="t('rides.ranks.phone')" @keyup.enter="find">
                    <button type="button" class="btn btn-primary" @click="find">{{ t('rides.loyalty.find') }}</button>
                </div>
                <div v-if="driver === false" class="text-muted">{{ t('rides.loyalty.not_found') }}</div>
                <template v-else-if="driver">
                    <div class="d-flex flex-wrap gap-4 mb-3">
                        <div><div class="fs-12 text-muted">{{ t('rides.ranks.captain') }}</div><b>{{ driver.driver.name }} · {{ driver.driver.phone }}</b></div>
                        <div><div class="fs-12 text-muted">{{ t('rides.ranks.rank') }}</div><b>{{ driver.rank || '—' }}</b><span v-if="driver.probation?.active" class="text-muted fs-12"> ({{ t('rides.ranks.days_left', { n: driver.probation.days_left }) }})</span></div>
                        <div v-if="driver.falling_due_at"><div class="fs-12 text-muted">{{ t('rides.ranks.falling') }}</div><b class="text-warning">{{ driver.falling_due_at.slice(0, 10) }}</b></div>
                    </div>
                    <div class="d-flex flex-wrap gap-3 mb-3 fs-13">
                        <span v-for="(v, k) in shown(driver.metrics)" :key="k" class="badge bg-light text-dark p-2">{{ t(`rides.ranks.m.${k}`) }}: {{ v }}</span>
                    </div>
                    <div v-if="can('driver-ranks.create')" class="row g-2 align-items-end mb-3">
                        <div class="col-md-2"><select v-model="note.kind" class="form-select"><option v-for="k in ['note', 'warning', 'praise']" :key="k" :value="k">{{ t(`rides.ranks.kinds.${k}`) }}</option></select></div>
                        <div class="col-md-4"><input v-model="note.title" class="form-control" :placeholder="t('rides.ranks.note_title')"></div>
                        <div class="col-md-4"><input v-model="note.body" class="form-control" :placeholder="t('rides.ranks.note_body')"></div>
                        <div class="col-md-2"><button type="button" class="btn btn-primary w-100" @click="saveNote">{{ t('save') }}</button></div>
                    </div>
                    <table class="table table-sm fs-13 mb-0">
                        <tbody>
                            <tr v-for="n in driver.notes" :key="n.id">
                                <td><span class="badge bg-light text-dark">{{ t(`rides.ranks.kinds.${n.kind}`) }}</span></td>
                                <td><b>{{ n.title }}</b><div v-if="n.body" class="text-muted fs-12">{{ n.body }}</div></td>
                                <td>{{ t(`rides.ranks.appeal.${n.appeal_status}`) }}</td>
                                <td class="text-muted">{{ (n.created_at || '').slice(0, 10) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </div>
        </div>

        <WalletModal :show="!!form" :title="t('rides.ranks.tabs.ranks')" size="lg" @close="form = null">
            <template v-if="form">
                <div class="row g-2">
                    <div class="col-md-3"><label class="form-label">{{ t('rides.ranks.slug') }}</label><input v-model="form.slug" class="form-control" dir="ltr"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.ranks.name') }} (ع)</label><input v-model="form.name.ar" class="form-control" dir="rtl"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.ranks.name') }} (EN)</label><input v-model="form.name.en" class="form-control" dir="ltr"></div>
                    <div class="col-md-1"><label class="form-label">{{ t('rides.loyalty.color') }}</label><input v-model="form.color" type="color" class="form-control form-control-color w-100"></div>
                    <div class="col-md-2"><label class="form-label">{{ t('rides.loyalty.sort') }}</label><input v-model.number="form.sort_order" type="number" min="0" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.ranks.m.trips') }}</label><input v-model.number="form.min_trips" type="number" min="0" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.ranks.m.rating') }}</label><input v-model.number="form.rating" type="number" min="0" max="5" step="0.05" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.ranks.m.cleanliness') }}</label><input v-model.number="form.cleanliness" type="number" min="0" max="5" step="0.05" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.ranks.m.behavior') }}</label><input v-model.number="form.behavior" type="number" min="0" max="5" step="0.05" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.ranks.m.commitment') }} %</label><input v-model.number="form.commitment" type="number" min="0" max="100" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.ranks.m.completion') }} %</label><input v-model.number="form.completion" type="number" min="0" max="100" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.ranks.m.cancel') }} ≤ %</label><input v-model.number="form.cancel" type="number" min="0" max="100" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ t('rides.ranks.m.warnings') }} ≤</label><input v-model.number="form.max_warnings" type="number" min="0" max="99" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">{{ t('rides.loyalty.benefits') }} (ع)</label><textarea v-model="form.benefits.ar" rows="3" class="form-control" dir="rtl" :placeholder="t('rides.loyalty.one_per_line')"></textarea></div>
                    <div class="col-md-6"><label class="form-label">{{ t('rides.loyalty.benefits') }} (EN)</label><textarea v-model="form.benefits.en" rows="3" class="form-control" dir="ltr"></textarea></div>
                </div>
                <div class="form-check form-switch mt-3"><input id="prob" v-model="form.probation" class="form-check-input" type="checkbox"><label class="form-check-label" for="prob">{{ t('rides.ranks.probation_rank') }}</label></div>
                <div class="form-check form-switch"><input id="act" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="act">{{ t('wallet.common.active') }}</label></div>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="form = null">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" @click="save">{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Captain ranks (D-239..D-259): thresholds per rank, appeals, one captain's record.
const { t, locale } = useI18n();
const { can } = usePermission();
const { showError } = useToast();
const current = ref('ranks');
const ranks = ref([]);
const appeals = ref([]);
const payouts = ref([]);
const answers = reactive({});
const form = ref(null);
const phone = ref('');
const driver = ref(null);
const note = reactive({ kind: 'note', title: '', body: '' });
const word = (v) => (v ? v[locale.value] || v.ar || v.en || '' : '');

function shown(m) {
    if (!m) return {};
    return {
        trips: m.trips, rating: (m.rating / 100).toFixed(2), cleanliness: (m.cleanliness / 100).toFixed(2), behavior: (m.behavior / 100).toFixed(2),
        commitment: `${m.commitment_bp / 100}%`, completion: `${m.completion_bp / 100}%`, cancel: `${m.cancel_bp / 100}%`, warnings: m.warnings,
    };
}

function open(r) {
    form.value = {
        id: r?.id ?? null, slug: r?.slug ?? '', name: { ar: r?.name?.ar ?? '', en: r?.name?.en ?? '' }, color: r?.color ?? '#94A3B8',
        sort_order: r?.sort_order ?? (ranks.value.length ? Math.max(...ranks.value.map((x) => x.sort_order)) + 1 : 0), probation: r?.probation ?? false,
        min_trips: r?.min_trips ?? 0, rating: (r?.min_rating ?? 0) / 100, cleanliness: (r?.min_cleanliness ?? 0) / 100, behavior: (r?.min_behavior ?? 0) / 100,
        commitment: (r?.min_commitment_bp ?? 0) / 100, completion: (r?.min_completion_bp ?? 0) / 100, cancel: (r?.max_cancel_bp ?? 10000) / 100, max_warnings: r?.max_warnings ?? 99,
        benefits: { ar: (r?.benefits?.ar || []).join('\n'), en: (r?.benefits?.en || []).join('\n') }, status: r?.status ?? true,
    };
}

async function save() {
    const f = form.value;
    const split = (s) => s.split('\n').map((x) => x.trim()).filter(Boolean);
    const body = {
        slug: f.slug, name: f.name, color: f.color, sort_order: f.sort_order, probation: f.probation, status: f.status,
        min_trips: f.min_trips, min_rating: Math.round(f.rating * 100), min_cleanliness: Math.round(f.cleanliness * 100), min_behavior: Math.round(f.behavior * 100),
        min_commitment_bp: Math.round(f.commitment * 100), min_completion_bp: Math.round(f.completion * 100), max_cancel_bp: Math.round(f.cancel * 100), max_warnings: f.max_warnings,
        benefits: { ar: split(f.benefits.ar), en: split(f.benefits.en) },
    };
    try {
        if (f.id) await adminAxios.put(`/api/admin/v1/driver-ranks/${f.id}`, body);
        else await adminAxios.post('/api/admin/v1/driver-ranks', body);
        form.value = null;
        loadRanks();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(r) {
    if (!window.confirm(t('rides.document_types.confirm_delete'))) return;
    try {
        await adminAxios.delete(`/api/admin/v1/driver-ranks/${r.id}`);
        loadRanks();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function answer(a, accept) {
    try {
        await adminAxios.post(`/api/admin/v1/driver-ranks/appeals/${a.id}`, { accept, answer: answers[a.id] || (accept ? t('rides.ranks.accept') : t('rides.ranks.reject')) });
        loadAppeals();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function find() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/driver-ranks/driver', { params: { phone: phone.value } });
        driver.value = data.data || false;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function saveNote() {
    try {
        await adminAxios.post(`/api/admin/v1/driver-ranks/drivers/${driver.value.driver.id}/notes`, { ...note });
        note.title = '';
        note.body = '';
        find();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function loadRanks() {
    try { ranks.value = (await adminAxios.get('/api/admin/v1/driver-ranks')).data.data ?? []; } catch (error) { showError(extractApiErrorMessage(error)); }
}
async function loadAppeals() {
    try { appeals.value = (await adminAxios.get('/api/admin/v1/driver-ranks/appeals')).data.data ?? []; } catch (error) { showError(extractApiErrorMessage(error)); }
}

async function loadPayouts() {
    try { payouts.value = (await adminAxios.get('/api/admin/v1/driver-payouts')).data.data ?? []; } catch (error) { showError(extractApiErrorMessage(error)); }
}
async function intl(p, approve) {
    try {
        await adminAxios.post(`/api/admin/v1/driver-payouts/${p.driver.id}/international`, { approve, answer: answers['i' + p.driver.id] || null });
        loadPayouts();
    } catch (error) { showError(extractApiErrorMessage(error)); }
}
async function unfreeze(p) {
    if (!window.confirm(t('rides.ranks.payouts.confirm_unfreeze'))) return;
    try {
        await adminAxios.post(`/api/admin/v1/driver-payouts/${p.driver.id}/unfreeze`);
        loadPayouts();
    } catch (error) { showError(extractApiErrorMessage(error)); }
}

watch(current, (tab) => { if (tab === 'appeals') loadAppeals(); if (tab === 'payouts') loadPayouts(); });
onMounted(() => { loadRanks(); loadAppeals(); });
</script>
