<template>
    <div class="d-flex flex-wrap gap-2">
        <button
            v-for="o in options"
            :key="o.value"
            type="button"
            class="btn btn-sm rounded-pill"
            :class="isOn(o.value) ? 'btn-primary' : 'btn-outline-light text-default'"
            :disabled="disabled"
            :aria-pressed="isOn(o.value)"
            @click="toggle(o.value)"
        >{{ o.label }}</button>
    </div>
</template>

<script setup>
/** Pill choices: one value (`modelValue` a scalar) or several (`multiple`, an array, up to `max`). */
const props = defineProps({
    modelValue: { type: [String, Number, Array, null], default: null },
    options: { type: Array, required: true },
    multiple: { type: Boolean, default: false },
    max: { type: Number, default: 99 },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

function isOn(value) {
    return props.multiple ? (props.modelValue ?? []).includes(value) : props.modelValue === value;
}

function toggle(value) {
    if (! props.multiple) {
        emit('update:modelValue', value);

        return;
    }
    const list = [...(props.modelValue ?? [])];
    const i = list.indexOf(value);
    if (i >= 0) {
        list.splice(i, 1);
    } else if (list.length < props.max) {
        list.push(value);
    }
    emit('update:modelValue', list);
}
</script>
