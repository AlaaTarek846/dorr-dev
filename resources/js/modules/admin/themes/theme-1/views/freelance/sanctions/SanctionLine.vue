<template>
    <div class="fs-13">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge" :class="typeBadge(s.type)">{{ t(`freelance.sanctions.types.${s.type}`) }}</span>
            <span class="badge bg-light text-default">{{ t(`freelance.sanctions.states.${s.state}`) }}</span>
            <span v-if="showPerson" class="fw-semibold">
                <a href="#" @click.prevent="$emit('person', s)">{{ s.person || '#' + s.person_id }}</a>
                <span class="text-muted fs-11"> · {{ t(`freelance.sanctions.sides.${s.side}`) }}</span>
            </span>
            <span class="text-muted fs-11" dir="ltr">{{ day(s.starts_at) }} → {{ s.ends_at ? day(s.ends_at) : '∞' }}</span>
            <span class="text-muted fs-11">{{ t('freelance.sanctions.by', { name: s.issued_by }) }}</span>
            <span v-if="s.source !== 'manual'" class="text-muted fs-11">· {{ t(`freelance.sanctions.sources.${s.source}`) }}<span v-if="s.source_ref" dir="ltr"> {{ s.source_ref }}</span></span>
        </div>
        <div class="text-wrap mt-1">{{ s.reason }}</div>
        <div v-if="s.lift_note" class="text-muted fs-12">{{ t('freelance.sanctions.lift_note') }}: {{ s.lift_note }}</div>

        <div v-if="s.appeal" class="bg-light rounded p-2 mt-2 fs-12">
            <strong>{{ t('freelance.sanctions.appeal') }}</strong>
            <span class="badge ms-1" :class="s.appeal.status === 'open' ? 'bg-warning-transparent' : 'bg-light text-default'">{{ t(`freelance.sanctions.appeal_states.${s.appeal.status}`) }}</span>
            <div class="text-wrap">{{ s.appeal.note }}</div>
            <div v-if="s.appeal.decision_note" class="text-muted">{{ s.appeal_decided_by }}: {{ s.appeal.decision_note }}</div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-2">
            <button v-if="canLift && mode !== 'lift'" type="button" class="btn btn-sm btn-light" @click="mode = 'lift'">{{ t('freelance.sanctions.lift') }}</button>
            <button v-if="canDecide && mode !== 'appeal'" type="button" class="btn btn-sm btn-primary" @click="mode = 'appeal'">{{ t('freelance.sanctions.decide_appeal') }}</button>
        </div>
        <div v-if="mode === 'lift'" class="d-flex gap-2 mt-2">
            <input v-model="note" type="text" maxlength="500" class="form-control form-control-sm" :placeholder="t('freelance.sanctions.note')">
            <button type="button" class="btn btn-sm btn-success" :disabled="working" @click="lift">{{ t('freelance.sanctions.lift') }}</button>
            <button type="button" class="btn btn-sm btn-light" @click="mode = ''">{{ t('cancel') }}</button>
        </div>
        <div v-if="mode === 'appeal'" class="mt-2">
            <p class="text-muted fs-11 mb-1">{{ t('freelance.sanctions.appeal_hint') }}</p>
            <div class="d-flex flex-wrap gap-2 mb-2">
                <button v-for="d in ['upheld', 'reduced', 'lifted']" :key="d" type="button" class="btn btn-sm" :class="decision === d ? 'btn-primary' : 'btn-light'" @click="decision = d">{{ t(`freelance.sanctions.appeal_states.${d}`) }}</button>
                <input v-if="decision === 'reduced'" v-model="endsAt" type="date" class="form-control form-control-sm w-auto" :aria-label="t('freelance.sanctions.new_end')">
            </div>
            <div class="d-flex gap-2">
                <input v-model="note" type="text" maxlength="500" class="form-control form-control-sm" :placeholder="t('freelance.sanctions.note')">
                <button type="button" class="btn btn-sm btn-primary" :disabled="working || !decision || (decision === 'reduced' && !endsAt)" @click="decide">{{ t('freelance.sanctions.save') }}</button>
                <button type="button" class="btn btn-sm btn-light" @click="mode = ''">{{ t('cancel') }}</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// One sanction with its appeal, and what staff may do with it: lift it early, or (another member of staff) decide the appeal.
const props = defineProps({ s: { type: Object, required: true }, showPerson: { type: Boolean, default: false } });
const emit = defineEmits(['changed', 'person']);
const { t } = useI18n();
const { showSuccess, showError } = useToast();
const { can } = usePermission();
const endpoint = '/api/admin/v1/freelance-sanctions';

const canLift = computed(() => props.s.state === 'active' && can('freelance-sanctions.issue') && (props.s.type !== 'suspension' || can('freelance-sanctions.suspend')));
const canDecide = computed(() => props.s.appeal?.status === 'open' && can('freelance-sanctions.appeal'));
const mode = ref('');
const note = ref('');
const decision = ref('');
const endsAt = ref('');
const working = ref(false);
const day = (iso) => (iso || '').slice(0, 10);
const typeBadge = (type) => ({ warning: 'bg-info-transparent', chat_limit: 'bg-warning-transparent', work_limit: 'bg-warning-transparent', suspension: 'bg-danger-transparent' }[type]);

async function post(path, body) {
    working.value = true;
    try {
        await adminAxios.post(`${endpoint}/${props.s.uuid}/${path}`, body);
        mode.value = '';
        note.value = '';
        showSuccess(t('freelance.sanctions.saved'));
        emit('changed');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        working.value = false;
    }
}

const lift = () => post('lift', { note: note.value.trim() || undefined });
const decide = () => post('appeal', { decision: decision.value, ends_at: decision.value === 'reduced' ? endsAt.value : undefined, note: note.value.trim() || undefined });
</script>
