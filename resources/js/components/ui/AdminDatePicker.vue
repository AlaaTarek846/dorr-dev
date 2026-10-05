<template>
    <DatePicker
        v-model="dateValue"
        :input-id="inputId"
        :show-time="showTime"
        :time-only="timeOnly"
        :hour-format="'24'"
        date-format="yy-mm-dd"
        :placeholder="placeholder"
        :invalid="invalid"
        :disabled="disabled"
        :min-date="minDate"
        :max-date="maxDate"
        show-icon
        icon-display="input"
        show-button-bar
        :append-to="appendTo"
        class="admin-date-picker"
    />
</template>

<script setup>
import DatePicker from 'primevue/datepicker';
import { computed } from 'vue';

/**
 * PrimeVue DatePicker that keeps the string formats the admin pages already send to the API:
 *  - date only      → "YYYY-MM-DD"
 *  - with time      → "YYYY-MM-DDTHH:mm" (the old <input type="datetime-local"> format)
 *  - time only      → "HH:mm"
 * Empty value → "".
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    inputId: { type: String, default: undefined },
    showTime: { type: Boolean, default: false },
    timeOnly: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
    invalid: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    minDate: { type: Date, default: undefined },
    maxDate: { type: Date, default: undefined },
    appendTo: { type: String, default: 'self' },
});

const emit = defineEmits(['update:modelValue']);

const pad = (n) => String(n).padStart(2, '0');

function parse(value) {
    if (! value) {
        return null;
    }

    if (props.timeOnly) {
        const [h, m] = String(value).split(':').map(Number);
        const d = new Date();
        d.setHours(h || 0, m || 0, 0, 0);

        return d;
    }

    const [datePart, timePart = '00:00'] = String(value).split('T');
    const [y, mo, d] = datePart.split('-').map(Number);
    const [h, mi] = timePart.split(':').map(Number);

    if (! y || ! mo || ! d) {
        return null;
    }

    return new Date(y, mo - 1, d, h || 0, mi || 0);
}

function format(date) {
    if (! (date instanceof Date) || Number.isNaN(date.getTime())) {
        return '';
    }

    const time = `${pad(date.getHours())}:${pad(date.getMinutes())}`;

    if (props.timeOnly) {
        return time;
    }

    const day = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

    return props.showTime ? `${day}T${time}` : day;
}

const dateValue = computed({
    get: () => parse(props.modelValue),
    set: (value) => emit('update:modelValue', format(value)),
});
</script>

<style scoped>
.admin-date-picker {
    min-width: 170px;
}

.admin-date-picker :deep(.p-datepicker-input) {
    font-size: 0.8125rem;
}
</style>
