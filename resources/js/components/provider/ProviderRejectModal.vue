<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">{{ t('providers.reject_title') }}</h6>
                    <button type="button" class="btn-close" aria-label="Close" @click="close"></button>
                </div>
                <form @submit.prevent="submit">
                    <div class="modal-body">
                        <label for="provider-reject-reason" class="form-label">
                            {{ t('providers.reject_reason_label') }}
                            <span class="text-danger">*</span>
                        </label>
                        <textarea
                            id="provider-reject-reason"
                            v-model="reason"
                            rows="4"
                            class="form-control"
                            :class="{ 'is-invalid': error }"
                            :placeholder="t('providers.reject_reason_placeholder')"
                            @input="error = ''"
                        ></textarea>
                        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" :disabled="loading" @click="close">
                            {{ t('confirm.cancel') }}
                        </button>
                        <button type="submit" class="btn btn-danger btn-wave" :disabled="loading">
                            <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
                            {{ t('providers.reject') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    show: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'confirm']);

const { t } = useI18n();
const modalElement = ref(null);
const reason = ref('');
const error = ref('');
let modalInstance = null;

function openModal() {
    if (! modalElement.value) {
        return;
    }

    modalInstance ??= new window.bootstrap.Modal(modalElement.value);
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

function submit() {
    if (! reason.value.trim()) {
        error.value = t('providers.reject_reason_required');
        return;
    }

    emit('confirm', reason.value.trim());
}

function onModalHidden() {
    emit('close');
}

watch(() => props.show, (visible) => {
    if (visible) {
        reason.value = '';
        error.value = '';
        openModal();
    } else {
        closeModal();
    }
});

onMounted(() => {
    modalElement.value?.addEventListener('hidden.bs.modal', onModalHidden);
});

onUnmounted(() => {
    modalElement.value?.removeEventListener('hidden.bs.modal', onModalHidden);
    modalInstance?.dispose();
});
</script>
