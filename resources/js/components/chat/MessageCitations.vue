<template>
    <div v-if="citations.length" class="ai-citations mt-2">
        <button type="button" class="ai-citations__toggle" @click="expanded = !expanded">
            <i :class="expanded ? 'ri-arrow-up-s-line' : 'ri-arrow-down-s-line'"></i>
            {{ expanded ? t('ai_chat.hide_sources') : t('ai_chat.sources_title') }}
            <span class="badge bg-light text-muted fs-10">{{ t('ai_chat.sources_count', { count: citations.length }) }}</span>
        </button>

        <ul v-if="expanded" class="list-unstyled mb-0 mt-1 ai-citations__list">
            <li v-for="citation in citations" :key="citation.id" class="ai-citations__item">
                <i :class="fileIconFor(citation.file_name)" class="fs-14 text-muted"></i>
                <span class="ai-citations__file text-truncate" dir="auto">{{ citation.file_name || '—' }}</span>
                <span v-if="locationLabel(citation)" class="ai-citations__location text-muted">— {{ locationLabel(citation) }}</span>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { fileIconFor } from '../../utils/aiFileDisplay';

// Doc S14/S15/S37: renders ONLY the real citation metadata the backend
// computed (AiFileCitationResource) - never invents a page/sheet/slide
// value, and shows no internal chunk/embedding ids. Doc S15: no PDF
// viewer / navigation is built here since the backend cannot currently
// hand back an exact in-file jump target - source info is shown
// plainly instead of a fake "jump to source" link.
defineProps({
    citations: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();
const expanded = ref(false);

function locationLabel(citation) {
    const loc = citation.location || {};

    if (loc.page) {
        return t('ai_chat.citation_page', { page: loc.page });
    }

    if (loc.sheet && (loc.row_start || loc.row_end)) {
        return t('ai_chat.citation_sheet_rows', { sheet: loc.sheet, start: loc.row_start, end: loc.row_end });
    }

    if (loc.sheet) {
        return t('ai_chat.citation_sheet', { sheet: loc.sheet });
    }

    if (loc.slide) {
        return t('ai_chat.citation_slide', { slide: loc.slide });
    }

    if (loc.timestamp_start !== undefined && loc.timestamp_start !== null) {
        const start = formatTimestamp(loc.timestamp_start);
        const end = loc.timestamp_end !== undefined && loc.timestamp_end !== null ? formatTimestamp(loc.timestamp_end) : start;

        return t('ai_chat.citation_timestamp', { start, end });
    }

    if (loc.section) {
        return t('ai_chat.citation_section', { section: loc.section });
    }

    return null;
}

function formatTimestamp(seconds) {
    const whole = Math.max(0, Math.round(Number(seconds) || 0));
    const minutes = Math.floor(whole / 60);
    const secs = String(whole % 60).padStart(2, '0');

    return `${minutes}:${secs}`;
}
</script>

<style scoped>
.ai-citations__toggle {
    border: 0;
    background: none;
    padding: 0;
    font-size: 0.72rem;
    color: var(--text-muted, #8c9097);
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.ai-citations__list {
    border-top: 1px solid rgba(0, 0, 0, 0.06);
    padding-top: 0.35rem;
}

.ai-citations__item {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.72rem;
    padding: 0.15rem 0;
    max-width: 100%;
}

.ai-citations__file {
    max-width: 10rem;
}

.ai-citations__location {
    white-space: nowrap;
}
</style>
