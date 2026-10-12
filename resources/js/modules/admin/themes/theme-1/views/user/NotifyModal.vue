<template>
    <WalletModal :show="show" :title="t('users.notify.title')" size="md" @close="emit('close')">
        <form id="user-notify-form" @submit.prevent="send">
            <div class="alert alert-light border d-flex gap-2 align-items-start fs-13 py-2">
                <i class="ri-notification-3-line text-primary mt-1"></i>
                <span>{{ audienceText }}</span>
            </div>

            <CatalogTranslationTabs
                :languages="languages"
                :active-locale="activeLocale"
                :translation-tab-class="tabClass"
                :translation-tab-feedback="tabFeedback"
                @update:active-locale="activeLocale = $event"
            />

            <template v-if="form.translations[activeLocale]">
                <div class="mb-3">
                    <label class="form-label" for="notify-title">{{ t('users.notify.subject') }} <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="ri-text"></i></span>
                        <input
                            id="notify-title"
                            v-model="form.translations[activeLocale].title"
                            type="text"
                            class="form-control"
                            :class="cls(v$.translations[activeLocale]?.title)"
                            :dir="localeDir"
                            :placeholder="t('users.notify.subject_placeholder')"
                        >
                    </div>
                    <div v-if="msg(v$.translations[activeLocale]?.title)" class="invalid-feedback d-block">{{ msg(v$.translations[activeLocale]?.title) }}</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="notify-message">{{ t('users.notify.message') }} <span class="text-danger">*</span></label>
                    <textarea
                        id="notify-message"
                        v-model="form.translations[activeLocale].message"
                        rows="4"
                        class="form-control"
                        :class="cls(v$.translations[activeLocale]?.message)"
                        :dir="localeDir"
                        :placeholder="t('users.notify.message_placeholder')"
                    ></textarea>
                    <div v-if="msg(v$.translations[activeLocale]?.message)" class="invalid-feedback d-block">{{ msg(v$.translations[activeLocale]?.message) }}</div>
                </div>
            </template>

            <div class="form-check form-switch mb-1">
                <input id="notify-push" v-model="form.push" class="form-check-input" type="checkbox" role="switch">
                <label class="form-check-label" for="notify-push">{{ t('users.notify.push') }}</label>
            </div>
            <div class="text-muted fs-12">{{ t('users.notify.channels_hint') }}</div>
            <div v-if="generalError" class="text-danger mt-2 fs-13">{{ generalError }}</div>
        </form>
        <template #footer>
            <button type="button" class="btn btn-light" :disabled="sending" @click="emit('close')">{{ t('close') }}</button>
            <button type="submit" form="user-notify-form" class="btn btn-primary btn-wave" :disabled="sending">
                <span v-if="sending" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="ri-send-plane-line me-1"></i>{{ t('users.notify.send') }}
            </button>
        </template>
    </WalletModal>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import WalletModal from '../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';
import { useAvailableLanguagesStore } from '../../../../../../stores/availableLanguages';

const props = defineProps({
    show: { type: Boolean, default: false },
    /** 'user' (one customer), 'users' (a chosen group) or 'all' (every active customer). */
    audience: { type: String, default: 'all' },
    userIds: { type: Array, default: () => [] },
    /** The name shown for a single customer. */
    userName: { type: String, default: '' },
});

const emit = defineEmits(['close', 'sent']);

const { t } = useI18n();
const { showSuccess } = useToast();
const { requiredField, maxString } = useValidation();
const languagesStore = useAvailableLanguagesStore();

const languages = computed(() => languagesStore.items);
const activeLocale = ref('');
const localeDir = computed(() => ['ar', 'fa', 'ur', 'he'].includes(activeLocale.value) ? 'rtl' : 'ltr');
const sending = ref(false);
const generalError = ref('');
const form = reactive({ push: true, translations: {} });
// How many customers "everyone" reaches, asked of the server when the modal opens (the page's own counters only fill in
// once a filter is used).
const allCount = ref(null);

const audienceText = computed(() => {
    if (props.audience === 'all') {
        return allCount.value === null ? t('users.notify.to_all_loading') : t('users.notify.to_all', { count: allCount.value });
    }

    if (props.audience === 'users') {
        return t('users.notify.to_group', { count: props.userIds.length });
    }

    return t('users.notify.to_one', { name: props.userName || `#${props.userIds[0]}` });
});

const rules = computed(() => ({
    translations: Object.fromEntries(languages.value.map((lang) => [lang.code, {
        title: { required: requiredField('users.notify.subject'), max: maxString('users.notify.subject', 120) },
        message: { required: requiredField('users.notify.message'), max: maxString('users.notify.message', 500) },
    }])),
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const msg = (field) => field?.$errors?.[0]?.$message || '';
const cls = (field) => ({ 'is-invalid': Boolean(field?.$error) });

function tabState(code) {
    const entry = form.translations[code] ?? {};

    return {
        filled: Boolean((entry.title || '').trim() && (entry.message || '').trim()),
        failed: Boolean(v$.value.translations[code]?.$error),
    };
}

function tabClass(code) {
    const { filled, failed } = tabState(code);

    return { active: activeLocale.value === code, 'catalog-lang-tab--error': failed, 'catalog-lang-tab--valid': filled };
}

function tabFeedback(code) {
    const { filled, failed } = tabState(code);

    return { show: filled || failed, valid: filled && ! failed };
}

function reset() {
    form.push = true;
    form.translations = {};
    languages.value.forEach((lang) => { form.translations[lang.code] = { title: '', message: '' }; });
    activeLocale.value = languages.value[0]?.code ?? '';
    generalError.value = '';
    v$.value.$reset();
}

watch(() => props.show, async (open) => {
    if (open) {
        allCount.value = null;
        reset();

        if (props.audience === 'all') {
            adminAxios.get('/api/admin/v1/users/notify/audience')
                .then(({ data }) => { allCount.value = data.data?.active ?? 0; })
                .catch(() => { allCount.value = 0; });
        }

        await languagesStore.fetch();
        reset();
    }
});

async function send() {
    generalError.value = '';

    if (! await v$.value.$validate()) {
        // Show the language that is still incomplete.
        const bad = languages.value.find((lang) => v$.value.translations[lang.code]?.$error);

        if (bad) {
            activeLocale.value = bad.code;
        }

        return;
    }

    sending.value = true;

    try {
        const { data } = await adminAxios.post('/api/admin/v1/users/notify', {
            audience: props.audience,
            user_ids: props.audience === 'all' ? undefined : props.userIds,
            title: Object.fromEntries(languages.value.map((lang) => [lang.code, form.translations[lang.code].title])),
            message: Object.fromEntries(languages.value.map((lang) => [lang.code, form.translations[lang.code].message])),
            push: form.push,
        });

        showSuccess(t('users.notify.sent', { count: data.data?.recipients ?? 0 }));
        emit('sent');
        emit('close');
    } catch (error) {
        generalError.value = extractApiErrorMessage(error);
    } finally {
        sending.value = false;
    }
}
</script>
