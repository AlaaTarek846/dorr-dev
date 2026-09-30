<template>
    <Editor
        :id="id"
        :model-value="modelValue"
        :editor-style="resolvedEditorStyle"
        :placeholder="placeholder"
        :readonly="readonly || disabled"
        :formats="activeFormats"
        :class="rootClass"
        v-bind="$attrs"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <template #toolbar>
            <span class="ql-formats">
                <select
                    class="ql-header"
                    :title="t('rich_text_editor.tools.heading')"
                >
                    <option value="">{{ t('rich_text_editor.headers.normal') }}</option>
                    <option value="1">{{ t('rich_text_editor.headers.h1') }}</option>
                    <option value="2">{{ t('rich_text_editor.headers.h2') }}</option>
                    <option value="3">{{ t('rich_text_editor.headers.h3') }}</option>
                    <option value="4">{{ t('rich_text_editor.headers.h4') }}</option>
                    <option value="5">{{ t('rich_text_editor.headers.h5') }}</option>
                    <option value="6">{{ t('rich_text_editor.headers.h6') }}</option>
                </select>
                <select class="ql-font" :title="t('rich_text_editor.tools.font')">
                    <option selected value="">{{ t('rich_text_editor.fonts.sans') }}</option>
                    <option value="serif">{{ t('rich_text_editor.fonts.serif') }}</option>
                    <option value="monospace">{{ t('rich_text_editor.fonts.mono') }}</option>
                </select>
            </span>
            <span class="ql-formats">
                <button
                    class="ql-bold"
                    type="button"
                    :title="t('rich_text_editor.tools.bold')"
                ></button>
                <button
                    class="ql-italic"
                    type="button"
                    :title="t('rich_text_editor.tools.italic')"
                ></button>
                <button
                    class="ql-underline"
                    type="button"
                    :title="t('rich_text_editor.tools.underline')"
                ></button>
                <button
                    class="ql-strike"
                    type="button"
                    :title="t('rich_text_editor.tools.strike')"
                ></button>
            </span>
            <span class="ql-formats">
                <select class="ql-color" :title="t('rich_text_editor.tools.text_color')"></select>
                <select class="ql-background" :title="t('rich_text_editor.tools.highlight')"></select>
            </span>
            <span class="ql-formats">
                <button
                    class="ql-list"
                    value="ordered"
                    type="button"
                    :title="t('rich_text_editor.tools.ordered_list')"
                ></button>
                <button
                    class="ql-list"
                    value="bullet"
                    type="button"
                    :title="t('rich_text_editor.tools.bullet_list')"
                ></button>
                <button
                    class="ql-indent"
                    value="-1"
                    type="button"
                    :title="t('rich_text_editor.tools.outdent')"
                ></button>
                <button
                    class="ql-indent"
                    value="+1"
                    type="button"
                    :title="t('rich_text_editor.tools.indent')"
                ></button>
            </span>
            <span class="ql-formats">
                <select class="ql-align" :title="t('rich_text_editor.tools.align')">
                    <option selected value=""></option>
                    <option value="center"></option>
                    <option value="right"></option>
                    <option value="justify"></option>
                </select>
                <button
                    class="ql-direction"
                    value="rtl"
                    type="button"
                    :title="t('rich_text_editor.tools.rtl')"
                ></button>
            </span>
            <span class="ql-formats">
                <button
                    class="ql-blockquote"
                    type="button"
                    :title="t('rich_text_editor.tools.blockquote')"
                ></button>
                <button
                    class="ql-code-block"
                    type="button"
                    :title="t('rich_text_editor.tools.code')"
                ></button>
            </span>
            <span class="ql-formats">
                <button
                    class="ql-link"
                    type="button"
                    :title="t('rich_text_editor.tools.link')"
                ></button>
                <button
                    v-if="enableMedia"
                    class="ql-image"
                    type="button"
                    :title="t('rich_text_editor.tools.image')"
                ></button>
                <button
                    v-if="enableMedia"
                    class="ql-video"
                    type="button"
                    :title="t('rich_text_editor.tools.video')"
                ></button>
            </span>
            <span class="ql-formats">
                <button
                    class="ql-clean"
                    type="button"
                    :title="t('rich_text_editor.tools.clear')"
                ></button>
            </span>
        </template>
    </Editor>
</template>

<script setup>
import Editor from 'primevue/editor';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import 'quill/dist/quill.snow.css';
import {
    RICH_TEXT_DEFAULT_MIN_HEIGHT,
    RICH_TEXT_FORMATS,
    RICH_TEXT_FORMATS_NO_MEDIA,
} from '../../config/richTextEditor';

defineOptions({
    inheritAttrs: false,
});

const props = defineProps({
    modelValue: { type: String, default: '' },
    id: { type: String, default: undefined },
    placeholder: { type: String, default: '' },
    minHeight: { type: String, default: RICH_TEXT_DEFAULT_MIN_HEIGHT },
    invalid: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    enableMedia: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

const { t } = useI18n();

const activeFormats = computed(() => (
    props.enableMedia ? [...RICH_TEXT_FORMATS] : [...RICH_TEXT_FORMATS_NO_MEDIA]
));

const resolvedEditorStyle = computed(() => ({
    minHeight: props.minHeight,
}));

const rootClass = computed(() => [
    'catalog-rich-text-editor',
    'w-100',
    {
        'catalog-rich-text-editor--invalid': props.invalid,
        'catalog-rich-text-editor--disabled': props.disabled || props.readonly,
    },
]);
</script>
