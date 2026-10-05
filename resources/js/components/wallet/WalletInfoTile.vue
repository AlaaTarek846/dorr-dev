<template>
    <div class="wallet-tile">
        <span v-if="icon" class="wallet-tile__icon"><i :class="icon"></i></span>
        <div class="wallet-tile__text">
            <div class="wallet-tile__label">{{ label }}</div>
            <div class="wallet-tile__value"><slot /></div>
        </div>
    </div>
</template>

<script setup>
/** One labelled value inside a wallet detail modal (icon + small label + value). */
defineProps({
    label: { type: String, required: true },
    /** Remix icon class, e.g. "ri-user-line". */
    icon: { type: String, default: '' },
});
</script>

<style scoped>
.wallet-tile {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    height: 100%;
    padding: 0.75rem 0.9rem;
    border: 1px solid var(--default-border, #e9edf2);
    border-radius: 0.75rem;
    background: var(--custom-white, #fff);
}

.wallet-tile__icon {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.625rem;
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.1);
    color: rgb(var(--primary-rgb, 132, 90, 223));
    font-size: 1.05rem;
}

.wallet-tile__text {
    min-width: 0;
}

.wallet-tile__label {
    margin-bottom: 0.15rem;
    color: var(--text-muted, #8c9097);
    font-size: 0.75rem;
}

.wallet-tile__value {
    font-weight: 600;
    word-break: break-word;
}
</style>
