<template>
    <div class="card custom-card mk-card mb-0">
        <div class="mk-nav">
            <button
                v-if="item.kind === 'task'"
                type="button"
                class="btn btn-icon btn-sm rounded-circle flex-shrink-0"
                :class="done ? 'btn-success' : 'btn-outline-light text-muted'"
                :disabled="!canTick"
                :aria-label="done ? t('marketing.task_reopen') : t('marketing.task_done')"
                @click.stop="$emit('tick', !done)"
            ><i :class="done ? 'ri-check-line' : 'ri-checkbox-blank-circle-line'"></i></button>
            <span v-else class="mk-icon" :class="item.kind === 'ad' ? 'bg-pink-transparent text-pink' : 'bg-info-transparent text-info'">
                <i :class="item.kind === 'ad' ? 'ri-megaphone-line' : 'ri-image-line'"></i>
            </span>
            <router-link :to="to" class="mk-nav__body text-reset text-decoration-none">
                <span class="mk-nav__title d-block" :class="{ 'text-decoration-line-through text-muted': done }">{{ item.title }}</span>
                <span class="d-flex flex-wrap align-items-center gap-1 mt-1">
                    <span v-if="time" class="fs-12 fw-semibold text-muted mk-ltr">{{ time }}</span>
                    <span class="badge bg-info-transparent">{{ t(channelKey(item.channel)) }}</span>
                    <span v-if="item.has_blocking_issues" class="badge bg-danger-transparent"><i class="ri-error-warning-line me-1"></i>{{ t('marketing.has_blocking') }}</span>
                </span>
            </router-link>
            <span v-if="item.kind !== 'task'" class="badge mk-pill" :class="`bg-${status.tone}-transparent`">{{ t(status.key) }}</span>
            <router-link :to="to" class="text-muted" :aria-label="item.title"><i class="ri-arrow-right-s-line fs-20 mk-flip"></i></router-link>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { channelKey, contentStatus, timeOf } from '../marketingContext';

/** One calendar / list item: kind, time, channel, title, status; tasks get a tick box. */
const props = defineProps({
    item: { type: Object, required: true },
    to: { type: Object, required: true },
    canTick: { type: Boolean, default: false },
    showTime: { type: Boolean, default: true },
});
defineEmits(['tick']);

const { t } = useI18n();
const done = computed(() => props.item.status === 'DONE');
const status = computed(() => contentStatus(props.item.status));
const time = computed(() => (props.showTime ? timeOf(props.item.planned_at) : ''));
</script>
