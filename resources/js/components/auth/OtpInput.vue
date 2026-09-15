<template>
    <div class="otp-input-row">
        <input
            v-for="(digit, index) in digits"
            :id="`${inputId}-${index + 1}`"
            :key="index"
            :ref="(element) => setInputRef(element, index)"
            v-model="digits[index]"
            type="text"
            inputmode="numeric"
            maxlength="1"
            class="form-control form-control-lg text-center otp-input-digit"
            :class="{ 'is-invalid': invalid }"
            @input="onInput(index)"
            @keydown="onKeydown($event, index)"
            @paste="onPaste"
        >
    </div>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    length: {
        type: Number,
        default: 6,
    },
    invalid: {
        type: Boolean,
        default: false,
    },
    inputId: {
        type: String,
        default: 'otp',
    },
});

const emit = defineEmits(['update:modelValue']);

const digits = ref(Array.from({ length: props.length }, () => ''));
const inputRefs = ref([]);

function setInputRef(element, index) {
    if (element) {
        inputRefs.value[index] = element;
    }
}

watch(() => props.modelValue, (value) => {
    const chars = String(value ?? '').slice(0, props.length).split('');

    digits.value = Array.from({ length: props.length }, (_, index) => chars[index] ?? '');
}, { immediate: true });

function emitValue() {
    emit('update:modelValue', digits.value.join(''));
}

function onInput(index) {
    digits.value[index] = digits.value[index].replace(/\D/g, '').slice(0, 1);
    emitValue();

    if (digits.value[index] && index < props.length - 1) {
        inputRefs.value[index + 1]?.focus();
    }
}

function onKeydown(event, index) {
    if (event.key === 'Backspace' && ! digits.value[index] && index > 0) {
        inputRefs.value[index - 1]?.focus();
    }
}

function onPaste(event) {
    event.preventDefault();
    const pasted = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, props.length);

    digits.value = Array.from({ length: props.length }, (_, index) => pasted[index] ?? '');
    emitValue();

    const nextIndex = Math.min(pasted.length, props.length - 1);
    inputRefs.value[nextIndex]?.focus();
}
</script>

<style scoped>
.otp-input-row {
    display: flex;
    flex-wrap: nowrap;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
}

.otp-input-digit {
    width: 3rem;
    min-width: 2.5rem;
    flex: 0 0 auto;
    padding-inline: 0;
}
</style>
