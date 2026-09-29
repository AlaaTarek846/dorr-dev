<template>
    <div
        class="catalog-rte"
        :class="{ 'is-invalid': invalid, 'is-disabled': disabled }"
    >
        <div v-if="editor" class="catalog-rte__toolbar" role="toolbar" :aria-label="label">
            <div class="catalog-rte__group">
                <button
                    v-for="button in undoRedoButtons"
                    :key="button.name"
                    type="button"
                    class="catalog-rte__btn"
                    :class="{ 'is-active': isActive(button) }"
                    :title="t(button.labelKey)"
                    :aria-label="t(button.labelKey)"
                    :disabled="disabled || ! canRun(button)"
                    @click="run(button)"
                >
                    <i :class="button.icon"></i>
                </button>
            </div>

            <div class="catalog-rte__group">
                <button
                    type="button"
                    class="catalog-rte__btn catalog-rte__btn--block"
                    :class="{ 'is-active': editor.isActive('paragraph') }"
                    :title="t('common.paragraph')"
                    :aria-label="t('common.paragraph')"
                    :disabled="disabled"
                    @click="run({ command: 'setParagraph' })"
                >
                    <span class="catalog-rte__text">P</span>
                </button>
                <button
                    v-for="level in headingLevels"
                    :key="level"
                    type="button"
                    class="catalog-rte__btn catalog-rte__btn--block"
                    :class="{ 'is-active': editor.isActive('heading', { level }) }"
                    :title="`H${level}`"
                    :aria-label="`H${level}`"
                    :disabled="disabled"
                    @click="run({ command: 'toggleHeading', level })"
                >
                    <span class="catalog-rte__text">H{{ level }}</span>
                </button>
            </div>

            <div class="catalog-rte__group">
                <button
                    v-for="button in markButtons"
                    :key="button.name"
                    type="button"
                    class="catalog-rte__btn"
                    :class="{ 'is-active': isActive(button) }"
                    :title="t(button.labelKey)"
                    :aria-label="t(button.labelKey)"
                    :disabled="disabled"
                    @click="run(button)"
                >
                    <i :class="button.icon"></i>
                </button>
            </div>

            <div class="catalog-rte__group">
                <button
                    v-for="button in listButtons"
                    :key="button.name"
                    type="button"
                    class="catalog-rte__btn"
                    :class="{ 'is-active': isActive(button) }"
                    :title="t(button.labelKey)"
                    :aria-label="t(button.labelKey)"
                    :disabled="disabled"
                    @click="run(button)"
                >
                    <i :class="button.icon"></i>
                </button>
            </div>

            <div class="catalog-rte__group">
                <button
                    type="button"
                    class="catalog-rte__btn"
                    :class="{ 'is-active': editor.isActive('link') }"
                    :title="editor.isActive('link') ? t('common.edit_link') : t('common.add_link')"
                    :aria-label="t('common.add_link')"
                    :disabled="disabled"
                    @click="handleLink"
                >
                    <i class="ri-link"></i>
                </button>
                <button
                    v-if="editor.isActive('link')"
                    type="button"
                    class="catalog-rte__btn"
                    :title="t('common.remove_link')"
                    :aria-label="t('common.remove_link')"
                    :disabled="disabled"
                    @click="removeLink"
                >
                    <i class="ri-link-unlink"></i>
                </button>
                <button
                    type="button"
                    class="catalog-rte__btn"
                    :title="t('common.add_image')"
                    :aria-label="t('common.add_image')"
                    :disabled="disabled"
                    @click="handleImage"
                >
                    <i class="ri-image-line"></i>
                </button>
                <button
                    type="button"
                    class="catalog-rte__btn"
                    :title="t('common.blockquote')"
                    :aria-label="t('common.blockquote')"
                    :class="{ 'is-active': editor.isActive('blockquote') }"
                    :disabled="disabled"
                    @click="run({ command: 'toggleBlockquote' })"
                >
                    <i class="ri-double-quotes-l"></i>
                </button>
                <button
                    type="button"
                    class="catalog-rte__btn"
                    :title="t('common.horizontal_rule')"
                    :aria-label="t('common.horizontal_rule')"
                    :disabled="disabled"
                    @click="run({ command: 'setHorizontalRule' })"
                >
                    <i class="ri-subtract-line"></i>
                </button>
            </div>

            <div class="catalog-rte__group">
                <button
                    v-for="align in alignments"
                    :key="align"
                    type="button"
                    class="catalog-rte__btn"
                    :class="{ 'is-active': editor.isActive({ textAlign: align }) }"
                    :title="t(`common.align_${align}`)"
                    :aria-label="t(`common.align_${align}`)"
                    :disabled="disabled"
                    @click="run({ command: 'setTextAlign', align })"
                >
                    <i :class="alignIcons[align]"></i>
                </button>
                <button
                    type="button"
                    class="catalog-rte__btn"
                    :title="t('common.clear_formatting')"
                    :aria-label="t('common.clear_formatting')"
                    :disabled="disabled"
                    @click="run({ command: 'clearNodes' })"
                >
                    <i class="ri-eraser-line"></i>
                </button>
            </div>
        </div>

        <div
            v-if="! editor"
            class="catalog-rte__content catalog-rte__content--loading"
        >
            <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
        </div>
        <EditorContent
            v-else
            :editor="editor"
            class="catalog-rte__content"
            :style="{ minHeight }"
            :dir="dir"
        />

        <div v-if="invalid && errorMessage" class="invalid-feedback d-block mt-1">
            {{ errorMessage }}
        </div>
    </div>
</template>

<script setup>
import { onBeforeUnmount, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import Placeholder from '@tiptap/extension-placeholder';
import TextAlign from '@tiptap/extension-text-align';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    placeholder: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: 'Rich text editor',
    },
    dir: {
        type: String,
        default: 'ltr',
    },
    minHeight: {
        type: String,
        default: '220px',
    },
    invalid: {
        type: Boolean,
        default: false,
    },
    errorMessage: {
        type: String,
        default: null,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue', 'focus', 'blur']);

const { t } = useI18n();

const headingLevels = [2, 3, 4];

const alignIcons = {
    left: 'ri-align-left',
    center: 'ri-align-center',
    right: 'ri-align-right',
    justify: 'ri-align-justify',
};

const alignments = ['left', 'center', 'right', 'justify'];

const undoRedoButtons = [
    { name: 'undo', command: 'undo', icon: 'ri-arrow-go-back-line', labelKey: 'common.undo' },
    { name: 'redo', command: 'redo', icon: 'ri-arrow-go-forward-line', labelKey: 'common.redo' },
];

const markButtons = [
    { name: 'bold', command: 'toggleBold', icon: 'ri-bold', labelKey: 'common.bold' },
    { name: 'italic', command: 'toggleItalic', icon: 'ri-italic', labelKey: 'common.italic' },
    { name: 'underline', command: 'toggleUnderline', icon: 'ri-underline', labelKey: 'common.underline' },
    { name: 'strike', command: 'toggleStrike', icon: 'ri-strikethrough', labelKey: 'common.strikethrough' },
    { name: 'code', command: 'toggleCode', icon: 'ri-code-s-slash-line', labelKey: 'common.inline_code' },
];

const listButtons = [
    { name: 'bulletList', command: 'toggleBulletList', icon: 'ri-list-unordered', labelKey: 'common.bullet_list' },
    { name: 'orderedList', command: 'toggleOrderedList', icon: 'ri-list-ordered', labelKey: 'common.ordered_list' },
];

let lastEmitted = null;

const editor = useEditor({
    content: String(props.modelValue ?? ''),
    editable: ! props.disabled,
    extensions: [
        StarterKit.configure({
            heading: { levels: headingLevels },
            link: {
                openOnClick: false,
                autolink: true,
                HTMLAttributes: {
                    rel: 'noopener noreferrer nofollow',
                    target: '_blank',
                },
            },
        }),
        Image.configure({
            inline: false,
            allowBase64: false,
            HTMLAttributes: { loading: 'lazy' },
        }),
        Placeholder.configure({ placeholder: () => props.placeholder }),
        TextAlign.configure({ types: ['heading', 'paragraph'] }),
    ],
    editorProps: {
        attributes: {
            spellcheck: 'true',
            'aria-label': props.label,
        },
    },
    onUpdate: ({ editor: instance }) => {
        // An empty document serialises to "<p></p>", which would pass
        // required/min-length checks, so normalise it to an empty string.
        const html = instance.isEmpty ? '' : instance.getHTML();

        lastEmitted = html;
        emit('update:modelValue', html);
    },
    onFocus: () => emit('focus'),
    onBlur: () => emit('blur'),
});

function isActive(button) {
    return Boolean(editor.value?.isActive(button.name));
}

function canRun(button) {
    const instance = editor.value;

    if (! instance) {
        return false;
    }

    return button.name === 'undo'
        ? instance.can().chain().focus().undo().run()
        : instance.can().chain().focus().redo().run();
}

function run(button) {
    const instance = editor.value;

    if (! instance || props.disabled) {
        return;
    }

    const chain = instance.chain().focus();

    switch (button.command) {
    case 'setParagraph':
        chain.setParagraph().run();
        break;
    case 'toggleHeading':
        chain.toggleHeading({ level: button.level }).run();
        break;
    case 'setTextAlign':
        chain.setTextAlign(button.align).run();
        break;
    case 'clearNodes':
        chain.unsetAllMarks().clearNodes().run();
        break;
    default:
        chain[button.command]().run();
    }
}

function isSafeUrl(value) {
    const url = String(value ?? '').trim();

    if (! url) {
        return false;
    }

    return /^(https?:\/\/|mailto:|tel:)/i.test(url);
}

function handleLink() {
    const instance = editor.value;

    if (! instance || props.disabled) {
        return;
    }

    const current = instance.getAttributes('link').href ?? '';
    const input = window.prompt(t('common.link_url_prompt'), current);

    if (input === null) {
        return;
    }

    const url = input.trim();

    if (! url) {
        removeLink();
        return;
    }

    if (! isSafeUrl(url)) {
        window.alert(t('common.link_url_invalid'));
        return;
    }

    instance.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
}

function removeLink() {
    editor.value?.chain().focus().unsetLink().run();
}

function handleImage() {
    const instance = editor.value;

    if (! instance || props.disabled) {
        return;
    }

    const input = window.prompt(t('common.image_url_prompt'));

    if (input === null) {
        return;
    }

    const url = input.trim();

    if (! url) {
        return;
    }

    if (! isSafeUrl(url)) {
        window.alert(t('common.link_url_invalid'));
        return;
    }

    instance.chain().focus().setImage({ src: url }).run();
}

watch(
    () => props.modelValue,
    (value) => {
        const instance = editor.value;

        if (! instance) {
            return;
        }

        const next = String(value ?? '');

        if (next === lastEmitted || next === instance.getHTML()) {
            return;
        }

        lastEmitted = next;
        instance.commands.setContent(next, { emitUpdate: false });
    },
);

watch(
    () => props.disabled,
    (value) => {
        editor.value?.setEditable(! value);
    },
);

onBeforeUnmount(() => {
    editor.value?.destroy();
});
</script>

<style scoped>
.catalog-rte {
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: 0.5rem;
    background-color: #fff;
    overflow: hidden;
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.catalog-rte:focus-within {
    border-color: var(--bs-primary, #635bff);
    box-shadow: 0 0 0 0.25rem rgba(99, 91, 255, 0.15);
}

.catalog-rte.is-invalid {
    border-color: var(--bs-danger, #d63939);
}

.catalog-rte.is-invalid:focus-within {
    border-color: var(--bs-danger, #d63939);
    box-shadow: 0 0 0 0.25rem rgba(214, 57, 57, 0.15);
}

.catalog-rte.is-disabled {
    background-color: var(--bs-tertiary-bg, #f8f9fa);
}

.catalog-rte__toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    padding: 0.375rem 0.5rem;
    border-bottom: 1px solid var(--bs-border-color, #dee2e6);
    background-color: #f8f9fa;
}

.catalog-rte__group {
    display: flex;
    gap: 0.125rem;
    padding-inline-end: 0.375rem;
    border-inline-end: 1px solid var(--bs-border-color, #dee2e6);
}

.catalog-rte__group:last-child {
    padding-inline-end: 0;
    border-inline-end: none;
}

.catalog-rte__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: none;
    border-radius: 0.375rem;
    background: transparent;
    color: var(--bs-secondary-color, #6c757d);
    line-height: 1;
    cursor: pointer;
    transition: background-color 0.15s ease-in-out, color 0.15s ease-in-out;
}

.catalog-rte__btn:hover:not(:disabled) {
    background-color: rgba(99, 91, 255, 0.1);
    color: var(--bs-primary, #635bff);
}

.catalog-rte__btn.is-active {
    background-color: var(--bs-primary, #635bff);
    color: #fff;
}

.catalog-rte__btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.catalog-rte__text {
    font-size: 0.8rem;
    font-weight: 600;
    line-height: 1;
}

.catalog-rte__btn--block .catalog-rte__text:first-child {
    font-weight: 400;
}

.catalog-rte__content {
    padding: 0.75rem 0.875rem;
    overflow-y: auto;
    max-height: 420px;
}

.catalog-rte__content :deep(.tiptap) {
    outline: none;
    min-height: inherit;
    line-height: 1.7;
}

.catalog-rte__content :deep(.tiptap p) {
    margin-bottom: 0.5rem;
}

.catalog-rte__content :deep(.tiptap p:last-child) {
    margin-bottom: 0;
}

.catalog-rte__content :deep(.tiptap h2),
.catalog-rte__content :deep(.tiptap h3),
.catalog-rte__content :deep(.tiptap h4) {
    margin-bottom: 0.5rem;
    font-weight: 600;
}

.catalog-rte__content :deep(.tiptap ul),
.catalog-rte__content :deep(.tiptap ol) {
    margin-bottom: 0.5rem;
    padding-inline-start: 1.25rem;
}

.catalog-rte__content :deep(.tiptap blockquote) {
    margin: 0 0 0.5rem;
    padding: 0.25rem 0 0.25rem 0.75rem;
    border-inline-start: 3px solid var(--bs-primary, #635bff);
    color: var(--bs-secondary-color, #6c757d);
}

.catalog-rte__content :deep(.tiptap code) {
    padding: 0.1rem 0.3rem;
    border-radius: 0.25rem;
    background-color: rgba(99, 91, 255, 0.1);
    font-size: 0.875em;
}

.catalog-rte__content :deep(.tiptap pre) {
    padding: 0.75rem;
    border-radius: 0.375rem;
    background-color: #1e1e2e;
    color: #f8f8f2;
    overflow-x: auto;
}

.catalog-rte__content :deep(.tiptap pre code) {
    padding: 0;
    background: none;
    color: inherit;
}

.catalog-rte__content :deep(.tiptap hr) {
    margin: 0.75rem 0;
    border-top: 1px solid var(--bs-border-color, #dee2e6);
}

.catalog-rte__content :deep(.tiptap a) {
    color: var(--bs-primary, #635bff);
    text-decoration: underline;
}

.catalog-rte__content :deep(.tiptap img) {
    max-width: 100%;
    height: auto;
    border-radius: 0.375rem;
}

.catalog-rte__content :deep(.tiptap p.is-editor-empty:first-child::before) {
    content: attr(data-placeholder);
    float: left;
    height: 0;
    pointer-events: none;
    color: var(--bs-secondary-color, #adb5bd);
}

.catalog-rte__content--loading {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 120px;
}
</style>
