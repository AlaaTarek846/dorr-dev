<template>
    <div class="wallet-hero">
        <div class="d-flex align-items-center gap-3 min-w-0">
            <span class="wallet-hero__icon"><i :class="icon"></i></span>
            <div class="min-w-0">
                <div class="wallet-hero__label">{{ label }}</div>
                <div class="wallet-hero__value" dir="ltr">{{ value }}</div>
                <div v-if="subtitle" class="wallet-hero__subtitle">{{ subtitle }}</div>
            </div>
        </div>
        <div v-if="$slots.default" class="wallet-hero__side">
            <slot />
        </div>
    </div>
</template>

<script setup>
/** Summary strip at the top of a wallet detail modal: icon, headline value, and a slot for the status badge. */
defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    subtitle: { type: String, default: '' },
    icon: { type: String, default: 'ri-wallet-3-line' },
});
</script>

<style scoped>
.wallet-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border: 1px solid rgba(var(--primary-rgb, 132, 90, 223), 0.18);
    border-radius: 0.875rem;
    background: linear-gradient(135deg, rgba(var(--primary-rgb, 132, 90, 223), 0.1), rgba(var(--primary-rgb, 132, 90, 223), 0.02));
}

.wallet-hero__icon {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 3rem;
    height: 3rem;
    border-radius: 0.875rem;
    background: rgb(var(--primary-rgb, 132, 90, 223));
    color: #fff;
    font-size: 1.4rem;
}

.wallet-hero__label {
    color: var(--text-muted, #8c9097);
    font-size: 0.75rem;
}

.wallet-hero__value {
    font-size: 1.4rem;
    font-weight: 700;
    line-height: 1.3;
}

.wallet-hero__subtitle {
    color: var(--text-muted, #8c9097);
    font-size: 0.75rem;
}

.wallet-hero__side {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.35rem;
}
</style>
