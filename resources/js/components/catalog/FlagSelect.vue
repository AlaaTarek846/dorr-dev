<template>
    <div>
        <label v-if="label" :for="inputId" class="form-label">
            {{ label }}
            <span v-if="required" class="text-danger">*</span>
        </label>
        <div class="input-group">
            <span v-if="selectedCode" class="input-group-text bg-white px-2">
                <FlagImage :code="selectedCode" :width="24" :height="18" :size="24" />
            </span>
            <select
                :id="inputId"
                class="form-select"
                :class="{ 'is-invalid': invalid }"
                :value="modelValue"
                @change="onChange"
            >
                <option value="">{{ placeholder }}</option>
                <option v-for="flag in flags" :key="flag.id" :value="flag.id">
                    {{ flag.code }} — {{ flag.name || flag.code }}
                </option>
            </select>
        </div>
        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
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

const selectedCode = computed(() => {
    const selected = flags.value.find((flag) => Number(flag.id) === Number(props.modelValue));

    return selected?.code ?? '';
});

function onChange(event) {
    const value = event.target.value;

    emit('update:modelValue', value ? Number(value) : null);
}

onMounted(async () => {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/flags/dropdown');

        flags.value = data.data ?? [];
    } catch {
        flags.value = [];
    }
});
</script>
