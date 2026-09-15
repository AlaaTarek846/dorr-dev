<template>
    <div>
        <label v-if="label" :for="inputId" class="form-label">
            {{ label }}
            <span v-if="required" class="text-danger">*</span>
        </label>
        <select
            :id="inputId"
            class="form-select"
            :class="{ 'is-invalid': invalid }"
            :value="modelValue"
            @change="onChange"
        >
            <option value="">{{ placeholder }}</option>
            <option v-for="country in countries" :key="country.id" :value="country.id">
                {{ country.name || country.code }}
            </option>
        </select>
        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import adminAxios from '../../api/adminAxios';

defineProps({
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
        default: 'country-select',
    },
});

const emit = defineEmits(['update:modelValue']);

const countries = ref([]);

function onChange(event) {
    const value = event.target.value;

    emit('update:modelValue', value ? Number(value) : null);
}

onMounted(async () => {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/countries/dropdown');

        countries.value = data.data ?? [];
    } catch {
        countries.value = [];
    }
});
</script>
