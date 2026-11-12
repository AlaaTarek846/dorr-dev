<template>
    <MkModal :show="show" :title="type ? t(planKey(type)) : ''" :busy="busy" @close="$emit('close')">
        <p class="text-muted fs-13">{{ t('marketing.plan_write_note') }}</p>
        <label class="form-label" for="mk-plan-instructions">{{ t('marketing.instructions') }}</label>
        <textarea id="mk-plan-instructions" v-model="instructions" class="form-control" rows="3" maxlength="1000"></textarea>
        <template #footer>
            <button type="button" class="btn btn-light" :disabled="busy" @click="$emit('close')">{{ t('marketing.cancel') }}</button>
            <button type="button" class="btn btn-primary" :disabled="busy" @click="submit">
                <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="ri-sparkling-2-line me-1"></i>{{ t('marketing.plan_write') }}
            </button>
        </template>
    </MkModal>
</template>

<script setup>
import { ref, watch } from 'vue';
import { planKey, useMk } from '../marketingContext';
import MkModal from './MkModal.vue';

/** Starts writing a plan (202 + job); the board follows the job through `marketing.job.changed`. */
const props = defineProps({
    show: { type: Boolean, default: false },
    projectId: { type: String, required: true },
    type: { type: String, default: null },
});
const emit = defineEmits(['close', 'started']);

const { http, api, fail, t, toast } = useMk();
const busy = ref(false);
const instructions = ref('');

watch(() => props.show, (open) => {
    if (open) {
        instructions.value = '';
    }
});

async function submit() {
    busy.value = true;
    try {
        const { data } = await http.post(api(`/projects/${props.projectId}/plans`), {
            type: props.type,
            instructions: instructions.value.trim() || undefined,
        });
        toast.showSuccess(t('marketing.plan_started'));
        emit('started', data.data);
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}
</script>
