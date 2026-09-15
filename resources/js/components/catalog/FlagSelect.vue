<template>
    <div>
        <label v-if="label" class="form-label">
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

const { t } = useI18n();
const rootElement = ref(null);
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
    loading.value = true;

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
    } finally {
        loading.value = false;
    }
});

function selectFlag(id) {
    emit('update:modelValue', id ? Number(id) : null);
    closeDropdown();
}

function closeDropdown() {
    const toggle = rootElement.value?.querySelector('[data-bs-toggle="dropdown"]');

    if (! toggle || ! window.bootstrap?.Dropdown) {
        return;
    }

    window.bootstrap.Dropdown.getInstance(toggle)?.hide();
}
</script>

<style scoped>
.flag-select__toggle {
    min-height: 2.625rem;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--input-border, #dee2e6);
    border-radius: 0.375rem;
    background-color: var(--form-control-bg, #fff);
    color: inherit;
}

.flag-select__toggle.disabled {
    pointer-events: none;
    opacity: 0.65;
}

.flag-select__toggle.is-invalid {
    border-color: var(--bs-form-invalid-border-color, #dc3545);
}

.flag-select__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    color: var(--text-muted, #6c757d);
}

.flag-select__caret {
    color: var(--text-muted, #6c757d);
}

.flag-select__menu {
    max-height: 16rem;
    overflow-y: auto;
}

.flag-select__option-placeholder {
    color: var(--text-muted, #6c757d);
}

.flag-select__menu .dropdown-item.active,
.flag-select__menu .dropdown-item:active {
    background-color: rgba(var(--primary-rgb, 132, 90, 223), 0.12);
    color: inherit;
}
</style>
