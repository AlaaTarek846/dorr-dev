<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" :class="size ? `modal-${size}` : ''">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">{{ title }}</h6>
                    <button type="button" class="btn-close" aria-label="Close" @click="close"></button>
                </div>
                <div class="modal-body">
                    <slot />
                </div>
                <div v-if="$slots.footer" class="modal-footer">
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
