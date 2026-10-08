<template>
    <div class="ai-file-list">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="fw-semibold fs-13">{{ t('ai_chat.files_in_conversation') }}</span>
            <button type="button" class="btn btn-sm btn-primary-light" :disabled="disabled" @click="pickerInput.click()">
                <i class="ri-add-line align-middle me-1"></i>{{ t('ai_chat.add_files') }}
            </button>
            <input ref="pickerInput" type="file" class="d-none" multiple :accept="acceptAttr" @change="onPicked">
        </div>

        <div v-if="!files.length" class="text-center text-muted p-3 ai-file-list__empty">
            <i class="ri-folder-open-line fs-2 d-block mb-1"></i>
            <p class="fs-12 mb-0">{{ t('ai_chat.no_files_attached') }}</p>
            <p class="fs-11 mb-0">{{ t('ai_chat.no_files_attached_hint') }}</p>
        </div>

        <ul v-else class="list-unstyled mb-0 ai-file-list__items">
            <li v-for="file in files" :key="file.id" class="ai-file-list__item d-flex align-items-center gap-2">
                <label v-if="selectable && file.status === 'ready'" class="ai-file-list__checkbox">
                    <input
                        type="checkbox"
                        :checked="selectedIds.includes(file.id)"
                        @change="$emit('toggle-select', file.id)"
                    >
                </label>

                <i :class="fileIconFor(file.mime_type)" class="fs-16 text-muted"></i>

                <div class="flex-fill min-w-0">
                    <div class="text-truncate fs-13" :title="file.file_name">{{ file.file_name }}</div>
                    <div class="fs-11 text-muted d-flex align-items-center gap-2">
                        <span v-if="file.file_size">{{ formatFileSize(file.file_size) }}</span>
                        <AiFileStatusBadge :status="file.status" :error-message="file.processing_error" />
                    </div>
                </div>

                <button
                    type="button"
                    class="btn btn-icon btn-sm btn-light ai-file-list__remove"
                    :title="t('ai_chat.remove_file')"
                    @click="$emit('remove', file)"
                >
                    <i class="ri-close-line"></i>
                </button>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AiFileStatusBadge from './AiFileStatusBadge.vue';
import { fileIconFor, formatFileSize } from '../../utils/aiFileDisplay';

const props = defineProps({
    files: {
        type: Array,
        default: () => [],
    },
    // Doc S12/S13: when true, a READY file shows a checkbox so the user
    // can build an explicit per-message file scope. The composer's
    // default ("use all attached files") needs none of this.
    selectable: {
        type: Boolean,
        default: false,
    },
    selectedIds: {
        type: Array,
        default: () => [],
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    // Doc S32: server-authoritative allowed types, used only to narrow
    // the native file picker's `accept` hint - the backend remains the
    // real gate (AiFileUploadRequest/AiFileEngine), this is just a
    // convenience so the OS picker doesn't show obviously-rejected files.
    allowedMimeTypes: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['add', 'remove', 'toggle-select']);

const { t } = useI18n();
const pickerInput = ref(null);

const acceptAttr = computed(() => props.allowedMimeTypes.join(','));

function onPicked(event) {
    const picked = event.target.files;

    if (picked && picked.length) {
        emit('add', picked);
    }

    event.target.value = '';
}
</script>

<style scoped>
.ai-file-list__empty {
    border: 1px dashed var(--default-border, #e9edf1);
    border-radius: 0.6rem;
}

.ai-file-list__items {
    max-height: 14rem;
    overflow-y: auto;
}

.ai-file-list__item {
    padding: 0.4rem 0.1rem;
    border-bottom: 1px solid var(--default-border, #f1f3f5);
}

.ai-file-list__item:last-child {
    border-bottom: 0;
}

.ai-file-list__remove {
    flex: 0 0 auto;
}

.min-w-0 {
    min-width: 0;
}
</style>
