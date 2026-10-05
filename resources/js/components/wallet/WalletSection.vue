<template>
    <section class="wallet-section">
        <header class="wallet-section__header">
            <span v-if="icon" class="wallet-section__icon"><i :class="icon"></i></span>
            <h6 class="wallet-section__title">{{ title }}</h6>
            <div v-if="$slots.actions" class="ms-auto">
                <slot name="actions" />
            </div>
        </header>
        <div class="wallet-section__body" :class="{ 'wallet-section__body--flush': flush }">
            <slot />
        </div>
    </section>
</template>

<script setup>
/** A titled block inside a wallet detail modal. `flush` removes the body padding (for tables). */
defineProps({
    title: { type: String, required: true },
    icon: { type: String, default: '' },
    flush: { type: Boolean, default: false },
});
</script>

<style scoped>
.wallet-section {
    overflow: hidden;
    border: 1px solid var(--default-border, #e9edf2);
    border-radius: 0.75rem;
    background: var(--custom-white, #fff);
}

.wallet-section__header {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.7rem 1rem;
    border-bottom: 1px solid var(--default-border, #e9edf2);
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.04);
}

.wallet-section__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 0.5rem;
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.12);
    color: rgb(var(--primary-rgb, 132, 90, 223));
}

.wallet-section__title {
    margin: 0;
    font-size: 0.875rem;
    font-weight: 600;
}

.wallet-section__body {
    padding: 1rem;
}

.wallet-section__body--flush {
    padding: 0;
}
</style>
