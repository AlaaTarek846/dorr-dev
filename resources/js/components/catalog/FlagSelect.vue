<template>
    <div>
        <label v-if="label" class="form-label">
            {{ label }}
            <span v-if="required" class="text-danger">*</span>
        </label>

        <div ref="rootElement" class="dropdown w-100 flag-select">
            <button
                type="button"
                class="flag-select__toggle w-100 d-flex align-items-center gap-2"
                :class="{ 'is-invalid': invalid, disabled: loading || ! flags.length }"
                data-bs-toggle="dropdown"
                data-bs-auto-close="true"
                aria-expanded="false"
            >
                <FlagImage
                    v-if="selectedCode"
                    :key="selectedCode"
                    :code="selectedCode"
                    :width="24"
                    :height="18"
                    :size="24"
                />
                <span v-else class="flag-select__icon">
                    <i class="ri-flag-line"></i>
                </span>

                <span class="flex-grow-1 text-start">
                    {{ selectedLabel }}
                </span>

                <i class="ri-arrow-down-s-line flag-select__caret"></i>
            </button>

            <ul class="dropdown-menu w-100 flag-select__menu">
                <template v-if="loading">
                    <li>
                        <span class="dropdown-item-text text-muted py-2">
                            {{ t('languages.loading') }}
                        </span>
                    </li>
                </template>

                <template v-else-if="! flags.length">
                    <li>
                        <span class="dropdown-item-text text-muted py-2">
                            {{ t('languages.empty') }}
                        </span>
                    </li>
                </template>

                <template v-else>
                    <li>
                        <button
                            type="button"
                            class="dropdown-item d-flex align-items-center gap-2 py-2"
                            :class="{ active: ! modelValue }"
                            @click="selectFlag(null)"
                        >
                            <span class="flag-select__option-placeholder">{{ placeholder }}</span>
                        </button>
                    </li>

                    <li v-for="flag in flags" :key="flag.id">
                        <button
                            type="button"
                            class="dropdown-item d-flex align-items-center gap-2 py-2"
                            :class="{ active: Number(flag.id) === Number(modelValue) }"
                            @click="selectFlag(flag.id)"
                        >
                            <FlagImage
                                :key="`${flag.id}-${flag.code}`"
                                :code="flag.code"
                                :width="20"
                                :height="15"
                                :size="20"
                            />
                            <span class="flex-grow-1 text-start">
                                {{ flag.code }} — {{ flag.name || flag.code }}
                            </span>
                            <i
                                v-if="Number(flag.id) === Number(modelValue)"
                                class="ri-check-line text-success fs-16"
                            ></i>
                        </button>
                    </li>
                </template>
            </ul>
        </div>

        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
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
const loading = ref(false);

const selectedFlag = computed(() => flags.value.find(
    (flag) => Number(flag.id) === Number(props.modelValue),
) ?? null);

const selectedCode = computed(() => selectedFlag.value?.code ?? '');

const selectedLabel = computed(() => {
    if (! selectedFlag.value) {
        return props.placeholder;
    }

    const name = selectedFlag.value.name || selectedFlag.value.code;

    return `${selectedFlag.value.code} — ${name}`;
});

onMounted(async () => {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/flags/dropdown');

        flags.value = data.data ?? [];
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
