<template>
    <div>
        <slot name="label">
            <label
                v-if="label"
                :for="inputId"
                class="form-label text-default d-block text-start"
            >
                {{ label }}
            </label>
        </slot>

        <div class="input-group auth-form-input">
            <span class="input-group-text auth-form-input__icon">
                <i :class="icon"></i>
            </span>

            <input
                :id="inputId"
                v-model="model"
                :type="resolvedType"
                class="form-control form-control-lg"
                :class="{ 'is-invalid': Boolean(error) }"
                :placeholder="placeholder"
                :autocomplete="autocomplete"
            >

            <button
                v-if="passwordToggle"
                type="button"
                class="btn btn-light auth-form-input__toggle"
                @click="showPassword = !showPassword"
            >
                <i :class="showPassword ? 'ri-eye-line' : 'ri-eye-off-line'" class="align-middle"></i>
            </button>
        </div>

        <div v-if="error" class="invalid-feedback d-block">
            {{ error }}
        </div>
        <p v-else-if="hint" class="auth-form-input__hint fs-12 text-muted mb-0 mt-1">
            {{ hint }}
        </p>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';

const model = defineModel({
    type: String,
    default: '',
});

const props = defineProps({
    inputId: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        default: '',
    },
    icon: {
        type: String,
        required: true,
    },
    type: {
        type: String,
        default: 'text',
    },
    placeholder: {
        type: String,
        default: '',
    },
    autocomplete: {
        type: String,
        default: 'off',
    },
    error: {
        type: String,
        default: '',
    },
    passwordToggle: {
        type: Boolean,
        default: false,
    },
    hint: {
        type: String,
        default: '',
    },
});

const showPassword = ref(false);

const resolvedType = computed(() => {
    if (! props.passwordToggle) {
        return props.type;
    }

    return showPassword.value ? 'text' : 'password';
});
</script>

<style scoped>
.auth-form-input__icon {
    padding: 0.5rem 0.75rem;
    color: var(--text-muted, #6c757d);
    background-color: var(--form-control-bg, #fff);
    border-color: var(--input-border, #dee2e6);
}

.auth-form-input__icon i,
.auth-form-input__toggle i {
    font-size: 0.95rem;
    line-height: 1;
}

.auth-form-input__toggle {
    padding: 0.5rem 0.75rem;
    border-color: var(--input-border, #dee2e6);
}

.auth-form-input__hint {
    line-height: 1.5;
}
</style>
