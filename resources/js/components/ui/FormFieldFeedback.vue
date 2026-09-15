<template>
    <span
        v-if="show"
        class="input-group-text form-field-feedback"
        :class="{
            'form-field-feedback--valid': valid,
            'form-field-feedback--invalid': invalid,
        }"
    >
        <i :class="valid ? 'ri-check-line' : 'ri-error-warning-line'"></i>
    </span>
</template>

<script setup>
defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    valid: {
        type: Boolean,
        default: false,
    },
    invalid: {
        type: Boolean,
        default: false,
    },
});
</script>

<style scoped>
.form-field-feedback {
    border-inline-start: 0;
    padding-inline: 0.75rem;
    transition: background-color 0.15s ease, color 0.15s ease;
}

.form-field-feedback--valid {
    background-color: rgba(38, 191, 148, 0.12);
    color: #26bf94;
}

.form-field-feedback--invalid {
    background-color: rgba(230, 83, 60, 0.12);
    color: #e6533c;
}

.form-field-feedback i {
    font-size: 1.125rem;
    line-height: 1;
}
</style>
