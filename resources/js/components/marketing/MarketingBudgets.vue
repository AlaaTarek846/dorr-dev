<template>
    <div>
        <MkHeader :title="t('marketing.budgets')" :subtitle="project?.name || ''" :back="to('project', { projectId })">
            <button v-if="enabled && abilities.edit" type="button" class="btn btn-primary" @click="openForm(null)">
                <i class="ri-add-line me-1"></i>{{ t('marketing.budget_add') }}
            </button>
        </MkHeader>

        <MkUnavailable v-if="statusReady && !enabled" />
        <template v-else>
            <div class="alert alert-info fs-13">{{ t('marketing.budgets_note') }}</div>
            <div v-if="budgets === null" class="row g-3">
                <div v-for="i in 2" :key="i" class="col-lg-6"><div class="mk-skeleton" style="height: 170px"></div></div>
            </div>
            <div v-else-if="budgets.length === 0" class="card custom-card mk-card">
                <div class="card-body text-center py-5">
                    <span class="mk-icon bg-success-transparent text-success mb-3"><i class="ri-safe-2-line"></i></span>
                    <h6 class="fw-semibold">{{ t('marketing.budgets_empty') }}</h6>
                    <p class="text-muted mb-0">{{ t('marketing.budgets_empty_sub') }}</p>
                </div>
            </div>
            <div v-else class="row g-3">
                <div v-for="b in budgets" :key="b.id" class="col-lg-6">
                    <div class="card custom-card mk-card h-100 mb-0">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3">
                                <span class="mk-icon" :class="b.kind === 'ads' ? 'bg-success-transparent text-success' : 'bg-info-transparent text-info'">
                                    <i :class="b.kind === 'ads' ? 'ri-safe-2-line' : 'ri-robot-2-line'"></i>
                                </span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold">{{ b.kind === 'ads' ? t('marketing.budget_ads') : t('marketing.budget_ai') }}</div>
                                    <div class="text-muted fs-12">{{ day(b.period_start) }} – {{ day(b.period_end) }}</div>
                                </div>
                                <span class="badge mk-pill" :class="`bg-${statusTone(b.status)}-transparent`">{{ t(`marketing.budget_status.${['APPROVED', 'CLOSED'].includes(b.status) ? b.status : 'PENDING'}`) }}</span>
                            </div>
                            <div class="d-flex align-items-end gap-2 mt-3 flex-wrap">
                                <span class="fs-20 fw-bold mk-ltr">{{ amount(b, b.used) }}</span>
                                <span class="text-muted fs-13 pb-1">{{ t('marketing.of_cap', { cap: amount(b, b.cap) }) }}</span>
                                <span class="ms-auto fw-bold mk-ltr" :class="`text-${levelTone(b.level)}`">{{ b.pct ?? 0 }}%</span>
                            </div>
                            <MkBar class="mt-2" :value="b.pct ?? 0" :color="`rgb(var(--${levelTone(b.level)}-rgb))`" />
                            <div class="text-muted fs-12 mt-1">{{ t('marketing.alert_at', { pct: b.alert_pct }) }}</div>
                            <div v-if="b.kind === 'ads' && (b.estimated ?? 0) > 0" class="text-muted fs-12">
                                {{ t('marketing.estimated_apart', { amount: mkMoney(b.estimated, b.currency || currency) }) }}
                            </div>
                            <div v-if="b.level !== 'ok' && b.status === 'APPROVED'" class="fw-semibold fs-13 mt-2" :class="`text-${levelTone(b.level)}`">
                                {{ b.level === 'exceeded' ? t('marketing.budget_exceeded') : t('marketing.budget_warning') }}
                            </div>
                            <div v-if="b.status === 'PENDING'" class="text-warning fs-13 mt-2">{{ t('marketing.budget_pending_note') }}</div>
                            <div v-if="b.note" class="text-muted fs-12 mt-1">{{ b.note }}</div>

                            <div v-if="b.status !== 'CLOSED' && (abilities.approve_budget || abilities.edit || abilities.manage_team)" class="mk-actions mt-3">
                                <button v-if="b.status === 'PENDING' && abilities.approve_budget" type="button" class="btn btn-success btn-sm" :disabled="busy !== null" @click="approve(b)">
                                    <span v-if="busy === `approve:${b.id}`" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="ri-verified-badge-line me-1"></i>{{ t('marketing.approve_budget') }}
                                </button>
                                <button v-if="abilities.edit" type="button" class="btn btn-light btn-sm" @click="openForm(b)">{{ t('marketing.budget_change') }}</button>
                                <button v-if="abilities.manage_team" type="button" class="btn btn-outline-danger btn-sm" @click="closing = b">
                                    <i class="ri-lock-line me-1"></i>{{ t('marketing.budget_close') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- New budget / new cap -->
        <MkModal :show="formOpen" :title="editingBudget ? t('marketing.budget_change') : t('marketing.budget_add')" :busy="busy !== null" @close="formOpen = false">
            <template v-if="!editingBudget">
                <MkChips v-model="form.kind" :options="[{ value: 'ads', label: t('marketing.budget_ads') }, { value: 'ai', label: t('marketing.budget_ai') }]" />
                <p class="text-muted fs-12 mt-2">{{ form.kind === 'ads' ? t('marketing.budget_ads_sub') : t('marketing.budget_ai_sub') }}</p>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label" for="mk-b-from">{{ t('marketing.from') }}</label>
                        <input id="mk-b-from" v-model="form.start" type="date" class="form-control mk-ltr">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="mk-b-to">{{ t('marketing.to') }}</label>
                        <input id="mk-b-to" v-model="form.end" type="date" class="form-control mk-ltr" :min="form.start">
                    </div>
                </div>
            </template>
            <label class="form-label mt-3" for="mk-b-cap">{{ form.kind === 'ads' ? t('marketing.cap_money') : t('marketing.cap_calls') }}</label>
            <div class="input-group">
                <input
                    id="mk-b-cap"
                    v-model="form.cap"
                    class="form-control mk-ltr"
                    :class="{ 'is-invalid': form.cap && !capValue }"
                    :inputmode="form.kind === 'ads' ? 'decimal' : 'numeric'"
                    :placeholder="form.kind === 'ads' ? '0.00' : '0'"
                    maxlength="14"
                >
                <span v-if="form.kind === 'ads' && currency" class="input-group-text">{{ currency }}</span>
            </div>
            <label class="form-label fw-semibold fs-13 mt-3">{{ t('marketing.alert_line') }}</label>
            <MkChips v-model="form.alert" :options="[70, 80, 90].map((p) => ({ value: p, label: `${p}%` }))" />
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="formOpen = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy !== null || !formValid" @click="save">
                    <span v-if="busy === 'save'" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
                </button>
            </template>
        </MkModal>

        <MkModal :show="closing !== null" :title="t('marketing.budget_close')" :busy="busy !== null" @close="closing = null">
            <p class="mb-0">{{ t('marketing.budget_close_text') }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="closing = null">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-danger" :disabled="busy !== null" @click="close">
                    <span v-if="busy === 'close'" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.budget_close') }}
                </button>
            </template>
        </MkModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { localDay, mkMinorToInput, mkMoney, mkNormalizeDigits, mkParseMinor } from './marketingContext';
import useProjectPage from './parts/useProjectPage';
import MkHeader from './parts/MkHeader.vue';
import MkBar from './parts/MkBar.vue';
import MkChips from './parts/MkChips.vue';
import MkModal from './parts/MkModal.vue';
import MkUnavailable from './parts/MkUnavailable.vue';

/**
 * Budgets (U08): two caps that never mix — ad spend in money (counted from the results entered) and AI in calls
 * (refused by the server once used up). The owner sets or approves; a manager's cap waits for the owner.
 */
const {
    projectId, project, abilities, statusReady, enabled, currency, http, api, to, fail, day, t, toast, onPush,
} = useProjectPage('budgets');

const budgets = ref(null);
const busy = ref(null);
const formOpen = ref(false);
const editingBudget = ref(null);
const closing = ref(null);

const statusTone = (s) => (s === 'APPROVED' ? 'success' : s === 'CLOSED' ? 'secondary' : 'warning');
const levelTone = (l) => (l === 'exceeded' ? 'danger' : l === 'warning' ? 'warning' : 'success');
const amount = (b, value) => (b.kind === 'ads' ? mkMoney(value, b.currency || currency.value) : t('marketing.calls', { count: Number(value ?? 0) }));

async function load() {
    if (! enabled.value) return;
    try {
        const { data } = await http.get(api(`/projects/${projectId}/budgets`));
        budgets.value = data.data ?? [];
    } catch (error) {
        budgets.value = budgets.value ?? [];
        fail(error);
    }
}

async function approve(b) {
    busy.value = `approve:${b.id}`;
    try {
        await http.post(api(`/budgets/${b.id}/approve`), { lock_version: b.lock_version });
        toast.showSuccess(t('marketing.budget_approved'));
    } catch (error) {
        fail(error);
    } finally {
        busy.value = null;
        load();
    }
}

async function close() {
    busy.value = 'close';
    try {
        const { data } = await http.post(api(`/budgets/${closing.value.id}/close`));
        toast.showSuccess(data.message);
        closing.value = null;
    } catch (error) {
        fail(error);
    } finally {
        busy.value = null;
        load();
    }
}

// ---------------------------------------------------------------- form
const form = reactive({ kind: 'ads', start: '', end: '', cap: '', alert: 80 });

const capValue = computed(() => {
    if (! form.cap) return null;
    if (form.kind === 'ads') return mkParseMinor(form.cap);
    const clean = mkNormalizeDigits(form.cap);

    return /^\d+$/.test(clean) ? Number(clean) : null;
});
const formValid = computed(() => capValue.value !== null && capValue.value > 0 && (editingBudget.value || (form.start && form.end && form.end >= form.start)));

function openForm(b) {
    editingBudget.value = b;
    const now = new Date();
    form.kind = b?.kind ?? 'ads';
    form.start = b?.period_start ?? localDay(new Date(now.getFullYear(), now.getMonth(), 1));
    form.end = b?.period_end ?? localDay(new Date(now.getFullYear(), now.getMonth() + 1, 0));
    form.cap = b ? (b.kind === 'ads' ? mkMinorToInput(b.cap) : String(b.cap ?? '')) : '';
    form.alert = b?.alert_pct ?? 80;
    formOpen.value = true;
}

async function save() {
    busy.value = 'save';
    const capField = form.kind === 'ads' ? { cap_minor: capValue.value } : { cap_calls: capValue.value };
    try {
        const { data } = editingBudget.value
            ? await http.patch(api(`/budgets/${editingBudget.value.id}`), { lock_version: editingBudget.value.lock_version, alert_pct: form.alert, ...capField })
            : await http.post(api(`/projects/${projectId}/budgets`), {
                kind: form.kind, period_start: form.start, period_end: form.end, alert_pct: form.alert, ...capField,
            });
        toast.showSuccess(data.message || t('marketing.saved'));
        formOpen.value = false;
    } catch (error) {
        fail(error);
    } finally {
        busy.value = null;
        load();
    }
}

watch(() => form.kind, () => {
    if (! editingBudget.value) form.cap = '';
});
watch(enabled, (on) => on && load());
onPush(load);
onMounted(load);
</script>
