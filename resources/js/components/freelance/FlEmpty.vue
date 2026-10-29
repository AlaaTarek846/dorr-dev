<template>
    <div class="text-center py-5">
        <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
            <i :class="icon" class="fs-2 text-primary"></i>
        </span>
        <p class="fw-semibold mb-1">{{ title }}</p>
        <p v-if="text" class="text-muted mb-0">{{ text }}</p>
    </div>
</template>

<script setup>
/** The empty state of the countries / users lists: a round icon, a title and a line. */
defineProps({
    icon: { type: String, default: 'ri-inbox-line' },
    title: { type: String, required: true },
    text: { type: String, default: '' },
});
</script>
