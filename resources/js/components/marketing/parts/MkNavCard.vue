<template>
    <div class="card custom-card mk-card mb-0">
        <component :is="to ? 'router-link' : 'button'" :to="to || undefined" :type="to ? undefined : 'button'" class="mk-nav" @click="to ? null : $emit('click')">
            <span class="mk-icon" :class="`bg-${tone}-transparent text-${tone}`"><i :class="icon"></i></span>
            <span class="mk-nav__body">
                <span class="mk-nav__title d-block">{{ title }}</span>
                <span v-if="subtitle" class="mk-nav__sub d-block">{{ subtitle }}</span>
            </span>
            <slot name="trailing" />
            <i class="ri-arrow-right-s-line fs-20 text-muted mk-flip"></i>
        </component>
        <slot />
    </div>
</template>

<script setup>
defineProps({
    icon: { type: String, default: 'ri-folder-line' },
    tone: { type: String, default: 'primary' },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    to: { type: Object, default: null },
});

defineEmits(['click']);
</script>
