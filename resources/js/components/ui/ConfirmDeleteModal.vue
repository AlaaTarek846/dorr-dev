<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered confirm-delete-modal">
            <div class="modal-content">
                <div class="modal-body text-center p-4 pb-3">
                    <span class="avatar avatar-xl avatar-rounded bg-danger-transparent confirm-delete-modal__icon">
                        <i class="ri-delete-bin-line fs-24 text-danger"></i>
                    </span>
                    <h6 class="fw-semibold mb-2 mt-3">{{ title }}</h6>
                    <p class="text-muted mb-0 fs-13">{{ message }}</p>
                </div>
                <div class="modal-footer justify-content-center border-top-0 pt-0 pb-4 gap-2">
                    <button
                        type="button"
                        class="btn btn-light"
                        :disabled="loading"
                        @click="close"
                    >
                        {{ cancelText || t('confirm.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-danger btn-wave"
                        :disabled="loading"
                        @click="confirm"
                    >
                        <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
                        {{ loading ? (loadingText || t('confirm.deleting')) : (confirmText || t('confirm.delete')) }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
    message: {
        type: String,
        default: '',
    },
    confirmText: {
        type: String,
        default: '',
    },
    cancelText: {
        type: String,
        default: '',
    },
    loadingText: {
        type: String,
        default: '',
    },
    loading: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['confirm', 'close']);

const { t } = useI18n();
const modalElement = ref(null);
let modalInstance = null;

function openModal() {
    if (! modalElement.value) {
        return;
    }

    modalInstance ??= new window.bootstrap.Modal(modalElement.value, {
        backdrop: 'static',
        keyboard: ! props.loading,
    });
    modalInstance.show();
}

function closeModal() {
    modalInstance?.hide();
}

function close() {
    if (props.loading) {
        return;
    }

    closeModal();
    emit('close');
}

function confirm() {
    if (props.loading) {
        return;
    }

    emit('confirm');
}

function onModalHidden() {
    emit('close');
}

watch(
    () => props.show,
    (visible) => {
        if (visible) {
            openModal();
        } else {
            closeModal();
        }
    },
);

onMounted(() => {
    modalElement.value?.addEventListener('hidden.bs.modal', onModalHidden);
});

onUnmounted(() => {
    modalElement.value?.removeEventListener('hidden.bs.modal', onModalHidden);
    modalInstance?.dispose();
});
</script>

<style scoped>
.confirm-delete-modal {
    max-width: 22rem;
}

.confirm-delete-modal__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 4rem;
    height: 4rem;
}
</style>
