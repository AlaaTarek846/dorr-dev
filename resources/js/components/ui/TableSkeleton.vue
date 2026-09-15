<template>
    <tr
        v-for="row in rows"
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
        <td class="text-end pe-4">
            <div class="d-flex justify-content-end gap-2">
                <span class="placeholder rounded-1" style="width: 2rem; height: 2rem;"></span>
                <span class="placeholder rounded-1" style="width: 2rem; height: 2rem;"></span>
            </div>
        </td>
    </tr>
</template>

<script setup>
defineProps({
    rows: {
        type: Number,
        default: 8,
    },
});
</script>

<style scoped>
.table-skeleton-row td {
    padding-top: 1rem;
    padding-bottom: 1rem;
    vertical-align: middle;
}
</style>
