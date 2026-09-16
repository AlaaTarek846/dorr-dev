<template>
    <div>
        <label v-if="label" :for="inputId" class="form-label">
            {{ label }}
            <span v-if="required" class="text-danger">*</span>
        </label>
        <Select
            :id="inputId"
            :model-value="modelValue"
            :options="flags"
            option-label="name"
            option-value="id"
            :placeholder="placeholder"
            :filter="true"
            filter-placeholder="Search..."
            :filter-fields="['name', 'code']"
            :show-clear="true"
            :invalid="invalid"
            class="w-100"
            @update:model-value="emit('update:modelValue', $event)"
        >
            <template #value="{ value }">
                <div v-if="value && selectedFlag" class="d-flex align-items-center gap-2">
                    <FlagImage :code="selectedFlag.code" :width="24" :height="18" :size="24" />
                    <span>{{ selectedFlag.name }}</span>
                </div>
                <span v-else>{{ placeholder }}</span>
            </template>

            <template #option="{ option }">
                <div class="d-flex align-items-center gap-2">
                    <FlagImage :code="option.code" :width="24" :height="18" :size="24" />
                    <span>{{ option.name || option.code }}</span>
                </div>
            </template>
        </Select>
        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import Select from 'primevue/select';
import adminAxios from '../../api/adminAxios';
import FlagImage from '../ui/FlagImage.vue';

const props = defineProps({
    modelValue: {
        type: [Number, String, null],
        default: null,
    },
    label: {
        type: String,
        default: '',
    },
    placeholder: {
        type: String,
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
    invalid: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    inputId: {
        type: String,
        default: 'flag-select',
    },
});

const emit = defineEmits(['update:modelValue']);

const flags = ref([]);

const selectedFlag = computed(() => {
    return flags.value.find((flag) => Number(flag.id) === Number(props.modelValue)) ?? null;
});

onMounted(async () => {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/flags/dropdown');

        flags.value = (data.data ?? []).map((flag) => ({
            ...flag,
            id: flag.id ?? flag.code ?? null,
            name: String(flag.name ?? flag.label ?? flag.code ?? ''),
            code: String(flag.code ?? flag.alpha2 ?? flag.name ?? ''),
        }));
    } catch {
        flags.value = [];
    }
});
</script>
