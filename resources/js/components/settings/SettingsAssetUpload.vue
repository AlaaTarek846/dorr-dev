<template>
    <div class="settings-asset-field border rounded p-3 mb-3">
        <div class="d-sm-flex align-items-start justify-content-between gap-3">
            <div class="mb-2 mb-sm-0">
                <h6 class="fw-semibold mb-1">{{ label }}</h6>
                <p v-if="hint" class="fs-12 text-muted mb-0">{{ hint }}</p>
            </div>
            <div class="btn-group btn-group-sm flex-shrink-0">
                <button type="button" class="btn btn-primary-light" @click="fileInput?.click()">
                    {{ t('platform_settings.change_file') }}
                </button>
                <button
                    type="button"
                    class="btn btn-light"
                    :disabled="! hasFile"
                    @click="emit('remove')"
                >
                    {{ t('platform_settings.remove_file') }}
                </button>
            </div>
        </div>

        <input
            ref="fileInput"
            type="file"
            class="d-none"
            :accept="accept"
            @change="onFileChange"
        >

        <div class="mt-3">
            <template v-if="isImage">
                <img
                    v-if="previewUrl"
                    :src="previewUrl"
                    :alt="label"
                    class="settings-asset-preview border rounded"
                >
                <p v-else class="fs-12 text-muted mb-0">{{ t('platform_settings.no_file') }}</p>
            </template>
            <template v-else>
                <a
                    v-if="previewUrl"
                    :href="previewUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="fs-12"
                >
                    {{ displayName }}
                </a>
                <p v-else class="fs-12 text-muted mb-0">{{ t('platform_settings.no_file') }}</p>
            </template>
        </div>

        <div v-if="error" class="text-danger fs-12 mt-2">
            {{ error }}
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    label: {
        type: String,
        required: true,
    },
    hint: {
        type: String,
        default: '',
    },
    accept: {
        type: String,
        default: '',
    },
    previewUrl: {
        type: String,
        default: '',
    },
    isImage: {
        type: Boolean,
        default: true,
    },
    error: {
        type: String,
        default: '',
    },
    hasFile: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['select', 'remove']);

const { t } = useI18n();
const fileInput = ref(null);

const displayName = computed(() => {
    if (! props.previewUrl) {
        return '';
    }

    const parts = props.previewUrl.split('/');

    return parts[parts.length - 1] || t('platform_settings.view_file');
});

function onFileChange(event) {
    const file = event.target.files?.[0];

    if (! file) {
        return;
    }

    emit('select', file);
}

function resetInput() {
    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

defineExpose({ resetInput });
</script>

<style scoped>
.settings-asset-preview {
    width: 64px;
    height: 64px;
    object-fit: contain;
    background: var(--default-body-bg-color, #f8f9fa);
}
</style>
