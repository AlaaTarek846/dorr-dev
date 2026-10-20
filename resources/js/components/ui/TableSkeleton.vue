<template>
    <template v-if="layout">
        <tr v-for="row in rows" :key="`layout-${row}`" class="table-skeleton-row placeholder-glow">
            <td v-for="(cell, index) in layout" :key="index" :class="{ 'ps-4': index === 0, 'text-end pe-4': cell === 'actions' }">
                <span v-if="cell === 'check'" class="placeholder rounded-1" style="width: 1rem; height: 1rem;"></span>
                <div v-else-if="cell === 'avatar-text'" class="d-flex align-items-center gap-2">
                    <span class="placeholder rounded-circle flex-shrink-0" style="width: 32px; height: 32px;"></span>
                    <div class="flex-grow-1">
                        <span class="placeholder col-8 mb-1 d-block"></span>
                        <span class="placeholder col-3 placeholder-sm d-block"></span>
                    </div>
                </div>
                <div v-else-if="cell === 'two-lines'">
                    <span class="placeholder col-9 mb-1 d-block"></span>
                    <span class="placeholder col-5 placeholder-sm d-block"></span>
                </div>
                <span v-else-if="cell === 'badge'" class="placeholder rounded-pill" style="width: 4.25rem; height: 1.5rem;"></span>
                <span v-else-if="cell === 'toggle'" class="placeholder rounded-pill" style="width: 2.5rem; height: 1.25rem;"></span>
                <div v-else-if="cell === 'actions'" class="d-flex justify-content-end gap-2">
                    <span class="placeholder rounded-1" style="width: 2rem; height: 2rem;"></span>
                    <span class="placeholder rounded-1" style="width: 2rem; height: 2rem;"></span>
                </div>
                <span v-else class="placeholder col-8 placeholder-sm d-block"></span>
            </td>
        </tr>
    </template>
    <tr
        v-for="row in (layout ? 0 : rows)"
        :key="row"
        class="table-skeleton-row placeholder-glow"
    >
        <td class="ps-4">
            <span class="placeholder rounded-1" style="width: 1rem; height: 1rem;"></span>
        </td>
        <td>
            <div class="d-flex align-items-center gap-2">
                <span class="placeholder rounded-1 flex-shrink-0" style="width: 32px; height: 24px;"></span>
                <div class="flex-grow-1">
                    <span class="placeholder col-8 mb-1 d-block"></span>
                    <span class="placeholder col-3 placeholder-sm d-block"></span>
                </div>
            </div>
        </td>
        <td>
            <span class="placeholder col-4 placeholder-sm rounded-pill"></span>
        </td>
        <td>
            <span class="placeholder rounded-pill" style="width: 4.25rem; height: 1.75rem;"></span>
        </td>
        <td>
            <span class="placeholder col-10 mb-1 d-block"></span>
            <span class="placeholder col-6 placeholder-sm d-block"></span>
        </td>
        <td
            v-for="index in extraColumns"
            :key="`skeleton-extra-${index}`"
        >
            <span class="placeholder col-8 placeholder-sm d-block"></span>
        </td>
        <td class="text-end pe-4">
            <div class="d-flex justify-content-end gap-2">
                <span class="placeholder rounded-1" style="width: 2rem; height: 2rem;"></span>
                <span class="placeholder rounded-1" style="width: 2rem; height: 2rem;"></span>
            </div>
        </td>
    </tr>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    rows: {
        type: Number,
        default: 8,
    },
    columns: {
        type: Number,
        default: 6,
    },
    /** Optional cell kinds per column: check | avatar-text | two-lines | badge | toggle | actions | text. */
    layout: {
        type: Array,
        default: null,
    },
});

const extraColumns = computed(() => Math.max(0, props.columns - 6));
</script>

<style scoped>
.table-skeleton-row td {
    padding-top: 1rem;
    padding-bottom: 1rem;
    vertical-align: middle;
}
</style>
