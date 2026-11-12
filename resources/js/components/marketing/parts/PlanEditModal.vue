<template>
    <MkModal :show="show" :title="t('marketing.edit_plan')" :busy="busy" wide @close="$emit('close')">
        <div class="alert alert-info fs-13">{{ t('marketing.edit_note') }}</div>
        <div class="mb-3">
            <label class="form-label" for="mk-pe-title">{{ t('marketing.title_field') }}</label>
            <input id="mk-pe-title" v-model="form.title" class="form-control" maxlength="200">
        </div>
        <div class="mb-3">
            <label class="form-label" for="mk-pe-summary">{{ t('marketing.summary') }}</label>
            <textarea id="mk-pe-summary" v-model="form.summary" class="form-control" rows="4" maxlength="4000"></textarea>
        </div>
        <template v-if="type === 'gtm'">
            <div v-for="(phase, i) in phases" :key="i" class="mb-3">
                <label class="form-label" :for="`mk-pe-phase-${i}`">{{ [phase.title, t('marketing.plan.tasks')].filter(Boolean).join(' · ') }}</label>
                <textarea :id="`mk-pe-phase-${i}`" v-model="form.phaseTasks[i]" class="form-control" rows="3" :placeholder="t('marketing.one_per_line')"></textarea>
            </div>
        </template>
        <div class="mb-3">
            <label class="form-label" for="mk-pe-assumptions">{{ t('marketing.plan.assumptions') }}</label>
            <textarea id="mk-pe-assumptions" v-model="form.assumptions" class="form-control" rows="3" :placeholder="t('marketing.one_per_line')"></textarea>
        </div>
        <div>
            <label class="form-label" for="mk-pe-risks">{{ t('marketing.plan.risks') }}</label>
            <textarea id="mk-pe-risks" v-model="form.risks" class="form-control" rows="3" :placeholder="t('marketing.one_per_line')"></textarea>
        </div>
        <template #footer>
            <button type="button" class="btn btn-light" :disabled="busy" @click="$emit('close')">{{ t('marketing.cancel') }}</button>
            <button type="button" class="btn btn-primary" :disabled="busy || !form.title.trim() || !form.summary.trim()" @click="save">
                <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
            </button>
        </template>
    </MkModal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useMk } from '../marketingContext';
import MkModal from './MkModal.vue';

/**
 * Edits what a person writes by hand: title, summary, assumptions, risks (one per line) and, for a go-to-market plan,
 * each phase's tasks. The rest of the content goes back unchanged. Saving makes a new version (`origin: edit`).
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    planId: { type: String, required: true },
    type: { type: String, required: true },
    version: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);

const { http, api, fail, t, toast } = useMk();
const busy = ref(false);
const form = reactive({ title: '', summary: '', assumptions: '', risks: '', phaseTasks: [] });

const content = computed(() => props.version?.content ?? {});
const phases = computed(() => (Array.isArray(content.value.phases) ? content.value.phases : []));
const lines = (text) => [...new Set(String(text ?? '').split('\n').map((s) => s.trim()).filter(Boolean))];
const asList = (v) => (Array.isArray(v) ? v.filter((x) => typeof x === 'string').join('\n') : '');

watch(() => props.show, (open) => {
    if (! open) return;
    form.title = content.value.title ?? '';
    form.summary = content.value.summary ?? '';
    form.assumptions = asList(content.value.assumptions);
    form.risks = asList(content.value.risks);
    form.phaseTasks = phases.value.map((p) => asList(p?.tasks));
});

async function save() {
    const next = JSON.parse(JSON.stringify(content.value));
    next.title = form.title.trim();
    next.summary = form.summary.trim();
    next.assumptions = lines(form.assumptions);
    next.risks = lines(form.risks);
    if (props.type === 'gtm') {
        next.phases = phases.value.map((p, i) => ({ ...JSON.parse(JSON.stringify(p)), tasks: lines(form.phaseTasks[i]) }));
    }

    busy.value = true;
    try {
        const { data } = await http.put(api(`/plans/${props.planId}`), { version: props.version.version, content: next });
        toast.showSuccess(data.message || t('marketing.saved'));
        emit('saved', data.data);
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}
</script>
