<template>
    <div class="card custom-card mb-0 h-100">
        <div class="card-body d-flex align-items-center gap-3">
            <span class="avatar avatar-lg avatar-rounded flex-shrink-0" :class="`bg-${tone}-transparent`">
                <i :class="[icon, `text-${tone}`]" class="fs-4"></i>
            </span>
            <div class="min-w-0">
                <div class="text-muted fs-12 text-truncate">{{ label }}</div>
                <div class="fs-20 fw-semibold lh-sm" dir="ltr">{{ value }}</div>
                <div v-if="hint" class="text-muted fs-11 text-truncate">{{ hint }}</div>
            </div>
        </div>
    </div>
</template>

<script setup>
/** A number with an icon, like the tiles of the dashboard; the Freelance desks show their headline figures with it. */
defineProps({
    icon: { type: String, default: 'ri-bar-chart-2-line' },
    /** primary · success · warning · danger · info · secondary */
    tone: { type: String, default: 'primary' },
    label: { type: String, required: true },
    value: { type: [String, Number], default: '—' },
    hint: { type: String, default: '' },
});
</script>
