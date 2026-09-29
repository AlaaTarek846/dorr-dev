<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('mobile_app_color_defaults.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('mobile_app_color_defaults.subtitle') }}</span>
            </div>
        </div>

        <form v-if="canUpdate" @submit.prevent="submitSettings">
            <div class="row g-4">
                <div
                    v-for="section in sections"
                    :key="section.mode"
                    class="col-xl-6"
                >
                    <div class="card custom-card">
                        <div class="card-header">
                            <div class="card-title">{{ section.title }}</div>
                        </div>
                        <div class="card-body">
                            <div
                                v-for="(pair, rowIndex) in colorKeyPairs"
                                :key="`${section.mode}-row-${rowIndex}`"
                                class="row g-3"
                                :class="{ 'mb-3': rowIndex < colorKeyPairs.length - 1 }"
                            >
                                <div
                                    v-for="key in pair"
                                    :key="`${section.mode}-${key}`"
                                    class="col-md-6"
                                >
                                    <label class="form-label mb-1" :for="`${section.mode}-${key}`">
                                        {{ tokenLabel(key) }}
                                    </label>
                                    <div class="input-group mobile-color-input">
                                        <input
                                            :id="`${section.mode}-${key}`"
                                            v-model="form[section.tokensKey][key]"
                                            type="color"
                                            class="form-control form-control-color mobile-color-input__picker"
                                            :title="tokenLabel(key)"
                                            @input="onPickerInput(section.tokensKey, key)"
                                        >
                                        <input
                                            v-model="form[section.tokensKey][key]"
                                            type="text"
                                            class="form-control font-monospace text-uppercase"
                                            :placeholder="t('mobile_app_color_defaults.hex_placeholder')"
                                            maxlength="7"
                                            spellcheck="false"
                                            @input="onHexInput(section.tokensKey, key, $event)"
                                            @blur="onHexBlur(section.tokensKey, key)"
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                    {{ submitting ? t('mobile_app_color_defaults.saving') : t('save_changes') }}
                </button>
            </div>
        </form>
        <div v-else class="alert alert-warning">
            {{ t('mobile_app_color_defaults.no_permission') }}
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

const COLOR_KEYS = [
    'primary', 'primaryLight', 'primaryDark', 'secondary', 'accent',
    'danger', 'success', 'warning', 'info',
    'background', 'surface', 'textPrimary', 'textSecondary', 'textMuted', 'border', 'headerBackground',
];

const { t } = useI18n();
const { canUpdate } = useCatalogPermissions('mobile_app_color_defaults');
const { showSuccess, showError } = useToast();

const submitting = ref(false);
const form = reactive({
    light_tokens: Object.fromEntries(COLOR_KEYS.map((k) => [k, '#000000'])),
    dark_tokens: Object.fromEntries(COLOR_KEYS.map((k) => [k, '#000000'])),
});

const sections = computed(() => [
    {
        mode: 'light',
        title: t('mobile_app_color_defaults.light'),
        tokensKey: 'light_tokens',
    },
    {
        mode: 'dark',
        title: t('mobile_app_color_defaults.dark'),
        tokensKey: 'dark_tokens',
    },
]);

const colorKeyPairs = computed(() => {
    const pairs = [];

    for (let i = 0; i < COLOR_KEYS.length; i += 2) {
        pairs.push(COLOR_KEYS.slice(i, i + 2));
    }

    return pairs;
});

function tokenLabel(key) {
    return t(`mobile_app_color_defaults.tokens.${key}`);
}

function normalizeHex(value) {
    if (! value) {
        return '#000000';
    }

    let hex = String(value).trim().replace(/^#/, '').replace(/[^0-9a-fA-F]/g, '').slice(0, 6);

    if (hex.length === 3) {
        hex = hex.split('').map((c) => c + c).join('');
    }

    if (hex.length === 0) {
        return '#000000';
    }

    return `#${hex.padEnd(6, '0').toUpperCase()}`;
}

function onPickerInput(tokensKey, key) {
    form[tokensKey][key] = normalizeHex(form[tokensKey][key]);
}

function onHexInput(tokensKey, key, event) {
    const raw = event.target.value;
    const withHash = raw.startsWith('#') ? raw : `#${raw}`;
    form[tokensKey][key] = withHash.toUpperCase();
}

function onHexBlur(tokensKey, key) {
    form[tokensKey][key] = normalizeHex(form[tokensKey][key]);
}

function fillForm(data) {
    COLOR_KEYS.forEach((key) => {
        form.light_tokens[key] = normalizeHex(data?.light_tokens?.[key]);
        form.dark_tokens[key] = normalizeHex(data?.dark_tokens?.[key]);
    });
}

async function loadSettings() {
    const { data } = await adminAxios.get('/api/admin/v1/mobile-app-color-defaults');
    fillForm(data.data ?? data);
}

async function submitSettings() {
    submitting.value = true;

    COLOR_KEYS.forEach((key) => {
        form.light_tokens[key] = normalizeHex(form.light_tokens[key]);
        form.dark_tokens[key] = normalizeHex(form.dark_tokens[key]);
    });

    try {
        const payload = {
            light_tokens: { ...form.light_tokens },
            dark_tokens: { ...form.dark_tokens },
        };
        const response = await adminAxios.put('/api/admin/v1/mobile-app-color-defaults', payload);
        showSuccess(extractApiMessage(response, t('toast.updated')));
        fillForm(response.data?.data ?? response.data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        submitting.value = false;
    }
}

onMounted(() => {
    loadSettings().catch((error) => showError(extractApiErrorMessage(error, t('toast.error'))));
});
</script>

<style scoped>
.mobile-color-input__picker {
    width: 3rem;
    min-width: 3rem;
    max-width: 3rem;
    height: 2.375rem;
    padding: 0.2rem;
    flex: 0 0 3rem;
    cursor: pointer;
}

.mobile-color-input .form-control.font-monospace {
    font-size: 0.8125rem;
    letter-spacing: 0.02em;
}
</style>
