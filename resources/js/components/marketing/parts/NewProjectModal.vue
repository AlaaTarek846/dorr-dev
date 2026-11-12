<template>
    <MkModal :show="show" :title="t('marketing.new_project')" :busy="busy" @close="$emit('close')">
        <form id="mk-new-project" @submit.prevent="submit">
            <div class="mb-3">
                <label class="form-label" for="mk-np-name">{{ t('marketing.project_name') }} <span class="text-danger">*</span></label>
                <input id="mk-np-name" v-model="form.name" class="form-control" :class="{ 'is-invalid': errors.name }" maxlength="120" required>
                <div v-if="errors.name" class="invalid-feedback">{{ errors.name[0] }}</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="mk-np-industry">{{ t('marketing.industry') }} <span class="text-danger">*</span></label>
                <input id="mk-np-industry" v-model="form.industry" class="form-control" :class="{ 'is-invalid': errors.industry }" maxlength="120" :placeholder="t('marketing.industry_hint')" required>
                <div v-if="errors.industry" class="invalid-feedback">{{ errors.industry[0] }}</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="mk-np-city">{{ t('marketing.city') }}</label>
                <input id="mk-np-city" v-model="form.city" class="form-control" maxlength="100">
            </div>
            <label class="form-label d-block">{{ t('marketing.stage') }}</label>
            <div class="d-flex flex-wrap gap-2">
                <button
                    v-for="s in STAGES"
                    :key="s"
                    type="button"
                    class="btn btn-sm rounded-pill"
                    :class="form.stage === s ? 'btn-primary' : 'btn-outline-light text-default'"
                    @click="form.stage = s"
                >{{ t(stageKey(s)) }}</button>
            </div>
        </form>
        <template #footer>
            <button type="button" class="btn btn-light" :disabled="busy" @click="$emit('close')">{{ t('marketing.cancel') }}</button>
            <button type="submit" form="mk-new-project" class="btn btn-primary" :disabled="busy || !valid">
                <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.create') }}
            </button>
        </template>
    </MkModal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { STAGES, stageKey, useMk } from '../marketingContext';
import MkModal from './MkModal.vue';

const props = defineProps({ show: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'created']);

const { http, api, fail, t, toast } = useMk();
const busy = ref(false);
const errors = ref({});
const form = reactive({ name: '', industry: '', city: '', stage: 'idea' });
const valid = computed(() => form.name.trim().length >= 2 && form.industry.trim().length >= 2);

watch(() => props.show, (open) => {
    if (open) {
        Object.assign(form, { name: '', industry: '', city: '', stage: 'idea' });
        errors.value = {};
    }
});

async function submit() {
    if (! valid.value || busy.value) {
        return;
    }
    busy.value = true;
    errors.value = {};
    try {
        const city = form.city.trim();
        const { data } = await http.post(api('/projects'), {
            name: form.name.trim(),
            industry: form.industry.trim(),
            stage: form.stage,
            cities: city ? [city] : undefined,
        });
        toast.showSuccess(data.message || t('marketing.created'));
        emit('created', data.data);
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors ?? {};
        }
        fail(error);
    } finally {
        busy.value = false;
    }
}
</script>
