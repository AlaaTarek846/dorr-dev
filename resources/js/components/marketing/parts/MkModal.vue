<template>
    <Teleport to="body">
        <Transition name="mk-modal">
            <div v-if="show" class="mk-modal" role="dialog" aria-modal="true" @mousedown.self="!busy && $emit('close')">
                <div class="mk-modal__panel" :class="{ 'mk-modal__panel--wide': wide }">
                    <div class="mk-modal__head">
                        <h6 class="mb-0 fw-semibold">{{ title }}</h6>
                        <button type="button" class="btn-close" :aria-label="t('marketing.close')" :disabled="busy" @click="$emit('close')"></button>
                    </div>
                    <div class="mk-modal__body">
                        <slot />
                    </div>
                    <div v-if="$slots.footer" class="mk-modal__foot">
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { onBeforeUnmount, watch } from 'vue';
import { useI18n } from 'vue-i18n';

/** A light dialog (no Bootstrap JS): centered on desktop, a bottom sheet on phones. Esc closes it. */
const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    busy: { type: Boolean, default: false },
    wide: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);
const { t } = useI18n();

function onKey(event) {
    if (event.key === 'Escape' && ! props.busy) {
        emit('close');
    }
}

watch(() => props.show, (open) => {
    if (open) {
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
    } else {
        document.removeEventListener('keydown', onKey);
        document.body.style.overflow = '';
    }
}, { immediate: true });

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey);
    document.body.style.overflow = '';
});
</script>

<style>
.mk-modal {
    position: fixed;
    inset: 0;
    z-index: 1080;
    background: rgba(15, 23, 42, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.mk-modal__panel {
    background: var(--custom-white, #fff);
    color: var(--default-text-color, inherit);
    width: min(100%, 32rem);
    max-height: calc(100vh - 2rem);
    border-radius: 1rem;
    display: flex;
    flex-direction: column;
    box-shadow: 0 1rem 3rem rgba(15, 23, 42, 0.2);
}

.mk-modal__panel--wide {
    width: min(100%, 46rem);
}

.mk-modal__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--default-border, #e9edf6);
}

.mk-modal__body {
    padding: 1.25rem;
    overflow-y: auto;
}

.mk-modal__foot {
    padding: 0.875rem 1.25rem;
    border-top: 1px solid var(--default-border, #e9edf6);
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
    flex-wrap: wrap;
}

.mk-modal-enter-active,
.mk-modal-leave-active {
    transition: opacity 0.2s ease;
}

.mk-modal-enter-from,
.mk-modal-leave-to {
    opacity: 0;
}

@media (max-width: 575.98px) {
    .mk-modal {
        align-items: flex-end;
        padding: 0;
    }

    .mk-modal__panel,
    .mk-modal__panel--wide {
        width: 100%;
        max-height: 92vh;
        border-radius: 1.25rem 1.25rem 0 0;
    }

    .mk-modal__foot > .btn {
        flex: 1 1 auto;
    }
}
</style>
