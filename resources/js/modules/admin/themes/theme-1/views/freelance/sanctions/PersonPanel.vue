<template>
    <div>
        <div v-if="loading && !person" class="text-center text-muted py-4">{{ t('flags.loading') }}</div>
        <template v-else-if="person">
            <!-- FL-707: for staff only; it never acts on the account by itself. -->
            <div class="border rounded p-3 mb-3">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <h6 class="fw-semibold mb-0">{{ t('freelance.sanctions.quality') }}</h6>
                    <span class="fs-18 fw-semibold">{{ person.quality.score ?? '—' }}<span class="text-muted fs-12"> / 100</span></span>
                    <span class="badge" :class="riskBadge(person.quality.level)">{{ t(`freelance.sanctions.risk.${person.quality.level}`) }}</span>
                    <span v-if="person.quality.score === null" class="text-muted fs-12">{{ t('freelance.sanctions.no_data') }}</span>
                </div>
                <ul v-if="person.quality.reasons.length" class="fs-12 mb-1 mt-2 ps-3">
                    <li v-for="r in person.quality.reasons" :key="r.code">
                        <span class="badge me-1" :class="riskBadge(r.level)">{{ t(`freelance.sanctions.risk.${r.level}`) }}</span>
                        {{ t(`freelance.sanctions.reasons.${r.code}`, { n: r.count ?? '' }) }}
                    </li>
                </ul>
                <p class="text-muted fs-11 mb-0 mt-2">
                    {{ t('freelance.sanctions.inputs', {
                        rating: person.quality.inputs.rating ?? '—', on_time: person.quality.inputs.on_time_rate ?? '—', completion: person.quality.inputs.completion_rate ?? '—',
                        disputes: person.quality.inputs.disputes_lost, reports: person.quality.inputs.reports_upheld, cancels: person.quality.inputs.cancel_rate ?? '—',
                    }) }}
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-3">
                <span v-for="what in ['chat', 'work']" :key="what" class="badge" :class="person.restrictions[what] ? 'bg-danger-transparent' : 'bg-success-transparent'">
                    {{ t(`freelance.sanctions.blocks.${what}`) }}:
                    {{ person.restrictions[what] ? (person.restrictions[what].until ? t('freelance.sanctions.until', { date: day(person.restrictions[what].until) }) : t('freelance.sanctions.until_lifted')) : t('freelance.sanctions.allowed') }}
                </span>
            </div>

            <!-- FL-708: a person issues it, with a reason; the account holder is told and may appeal to someone else. -->
            <div v-if="canIssue" class="border rounded p-3 mb-3">
                <h6 class="fw-semibold">{{ t('freelance.sanctions.issue') }}</h6>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <button v-for="ty in types" :key="ty" type="button" class="btn btn-sm" :class="form.type === ty ? 'btn-primary' : 'btn-light'" @click="form.type = ty">{{ t(`freelance.sanctions.types.${ty}`) }}</button>
                </div>
                <p v-if="form.type" class="text-muted fs-12 mb-2">{{ t(`freelance.sanctions.type_hints.${form.type}`) }}</p>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <input v-if="form.type && form.type !== 'warning'" v-model.number="form.days" type="number" min="1" :max="form.type === 'suspension' ? 365 : 30" class="form-control form-control-sm w-auto"
                           :placeholder="form.type === 'suspension' ? t('freelance.sanctions.days_optional') : t('freelance.sanctions.days')">
                    <select v-model="form.source" class="form-select form-select-sm w-auto">
                        <option v-for="s in sources" :key="s" :value="s">{{ t(`freelance.sanctions.sources.${s}`) }}</option>
                    </select>
                    <input v-if="form.source !== 'manual'" v-model="form.source_ref" type="text" maxlength="64" class="form-control form-control-sm w-auto" :placeholder="t('freelance.sanctions.source_ref')">
                </div>
                <textarea v-model="form.reason" class="form-control mb-2" rows="2" maxlength="500" :placeholder="t('freelance.sanctions.reason')"></textarea>
                <button type="button" class="btn btn-sm btn-danger" :disabled="!canSend || working" @click="issue">{{ t('freelance.sanctions.send') }}</button>
            </div>

            <h6 class="fw-semibold">{{ t('freelance.sanctions.history') }}</h6>
            <p v-if="!person.sanctions.length" class="text-muted fs-13">{{ t('freelance.sanctions.empty') }}</p>
            <div v-for="s in person.sanctions" :key="s.uuid" class="border-bottom py-2">
                <SanctionLine :s="s" @changed="reload" />
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import SanctionLine from './SanctionLine.vue';

// One person (client or freelancer): quality and risk with the reasons (FL-707), what limits them now, issuing a sanction, and every one so far (FL-708).
const props = defineProps({ side: { type: String, required: true }, id: { type: Number, required: true } });
const emit = defineEmits(['changed']);
const { t } = useI18n();
const { showSuccess, showError } = useToast();
const { can } = usePermission();
const endpoint = '/api/admin/v1/freelance-sanctions';

const canIssue = computed(() => can('freelance-sanctions.issue'));
const types = computed(() => ['warning', 'chat_limit', 'work_limit', ...(can('freelance-sanctions.suspend') ? ['suspension'] : [])]);
const sources = ['manual', 'message_report', 'review_objection', 'dispute'];
const person = ref(null);
const loading = ref(false);
const working = ref(false);
const form = reactive({ type: '', days: null, reason: '', source: 'manual', source_ref: '' });
const canSend = computed(() => form.type && form.reason.trim().length >= 5 && (!['chat_limit', 'work_limit'].includes(form.type) || form.days >= 1));

const day = (iso) => (iso || '').slice(0, 10);
const riskBadge = (level) => ({ high: 'bg-danger-transparent', medium: 'bg-warning-transparent', low: 'bg-success-transparent' }[level] ?? 'bg-light text-default');

async function reload() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(`${endpoint}/person/${props.side}/${props.id}`);
        person.value = data.data;
        emit('changed');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function issue() {
    working.value = true;
    try {
        await adminAxios.post(endpoint, {
            side: props.side, person_id: props.id, type: form.type, reason: form.reason.trim(),
            days: form.type === 'warning' ? undefined : (form.days || undefined),
            source: form.source, source_ref: form.source === 'manual' ? undefined : (form.source_ref.trim() || undefined),
        });
        Object.assign(form, { type: '', days: null, reason: '', source: 'manual', source_ref: '' });
        showSuccess(t('freelance.sanctions.issued'));
        await reload();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        working.value = false;
    }
}

watch(() => [props.side, props.id], () => { person.value = null; reload(); }, { immediate: true });
</script>
