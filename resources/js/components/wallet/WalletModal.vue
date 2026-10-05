<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" :class="size ? `modal-${size}` : ''">
            <div class="modal-content">
                <div class="modal-header wallet-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">{{ title }}</h6>
                        <button type="button" class="btn-close wallet-modal-close" aria-label="Close" @click="close"></button>
                    </div>
                </div>
                <div class="modal-body px-4 pb-3">
                    <slot />
                </div>
                <div v-if="$slots.footer" class="modal-footer wallet-modal-footer">
                    <slot name="footer" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    size: { type: String, default: 'lg' },
});

const emit = defineEmits(['close']);
const modalElement = ref(null);
let instance = null;

function open() {
    if (! modalElement.value) {
        return;
    }

    instance ??= new window.bootstrap.Modal(modalElement.value, { backdrop: 'static' });
    instance.show();
}

function close() {
    instance?.hide();
}

function onHidden() {
    emit('close');
}

watch(() => props.show, (visible) => (visible ? open() : close()));

onMounted(() => {
    modalElement.value?.addEventListener('hidden.bs.modal', onHidden);

    if (props.show) {
        open();
    }
});

onUnmounted(() => {
    modalElement.value?.removeEventListener('hidden.bs.modal', onHidden);
    instance?.dispose();
});
</script>

<style scoped>
.wallet-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.wallet-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.wallet-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    flex-shrink: 0;
    opacity: 0.65;
    background-size: 0.65rem;
}

.wallet-modal-close:hover {
    opacity: 1;
}

.wallet-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
}
</style>
