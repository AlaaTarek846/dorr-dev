<template>
    <Teleport to="body">
        <div v-if="guideStore.isOpen" class="offcanvas-backdrop fade show" @click="guideStore.close()"></div>

        <aside
            class="offcanvas offcanvas-end screen-guide"
            :class="{ show: guideStore.isOpen }"
            tabindex="-1"
            role="dialog"
            aria-modal="true"
            :aria-hidden="!guideStore.isOpen"
            :aria-label="t('guide.panel_title')"
        >
            <div class="offcanvas-header border-bottom border-block-end-dashed align-items-start">
                <div class="d-flex gap-2 align-items-start min-w-0">
                    <span class="avatar avatar-md bg-primary-transparent flex-shrink-0">
                        <i class="ri-question-line fs-18"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-muted fs-12 mb-0 text-truncate">
                            {{ t('guide.panel_title') }}<template v-if="place"> · {{ t(place.service.title) }} · {{ t(place.group.title) }}</template>
                        </p>
                        <h6 class="fw-semibold mb-0">{{ screenTitle }}</h6>
                    </div>
                </div>
                <button type="button" class="btn-close" :aria-label="t('guide.close')" @click="guideStore.close()"></button>
            </div>

            <div class="offcanvas-body p-0 d-flex flex-column">
                <div v-if="loading" class="p-4 placeholder-glow">
                    <span class="placeholder col-12 mb-2 d-block"></span>
                    <span class="placeholder col-10 mb-2 d-block"></span>
                    <span class="placeholder col-8 d-block"></span>
                </div>

                <div v-else-if="!content" class="p-4 text-center my-auto">
                    <span class="avatar avatar-xl bg-light mb-3"><i class="ri-draft-line fs-24 text-muted"></i></span>
                    <p class="fw-semibold mb-1">{{ t('guide.missing_title') }}</p>
                    <p class="text-muted mb-3">{{ t('guide.missing_body') }}</p>
                    <button v-if="place" type="button" class="btn btn-primary-light btn-sm" @click="openMap">
                        <i class="ri-road-map-line me-1 align-middle"></i>{{ t('guide.open_map') }}
                    </button>
                </div>

                <template v-else>
                    <div class="px-4 pt-3">
                        <p class="mb-2 fs-14 lh-lg">{{ content.summary }}</p>
                        <p v-if="content.audience" class="text-muted fs-12 mb-3">
                            <i class="ri-user-line me-1 align-middle"></i>{{ content.audience }}
                        </p>

                        <ul class="nav nav-tabs tab-style-2 mb-0 border-bottom-0 flex-nowrap overflow-auto" role="tablist">
                            <li v-for="tab in tabs" :key="tab.key" class="nav-item" role="presentation">
                                <button
                                    type="button"
                                    class="nav-link text-nowrap"
                                    :class="{ active: activeTab === tab.key }"
                                    role="tab"
                                    :aria-selected="activeTab === tab.key"
                                    @click="activeTab = tab.key"
                                >
                                    <i :class="`${tab.icon} me-1 align-middle`"></i>{{ t(tab.label) }}
                                    <span v-if="tab.count" class="badge rounded-pill ms-1" :class="tab.badgeClass">{{ tab.count }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div class="px-4 py-3 border-top flex-grow-1 overflow-auto">
                        <!-- Overview: what it is for + what it changes -->
                        <template v-if="activeTab === 'overview'">
                            <h6 class="fw-semibold fs-13 mb-2">{{ t('guide.purpose') }}</h6>
                            <ul class="list-unstyled guide-list mb-4">
                                <li v-for="(item, i) in content.purpose" :key="i">
                                    <i class="ri-checkbox-circle-line text-success"></i><span>{{ item }}</span>
                                </li>
                            </ul>

                            <h6 class="fw-semibold fs-13 mb-2">{{ t('guide.affects') }}</h6>
                            <ul class="list-unstyled guide-list mb-0">
                                <li v-for="(item, i) in content.affects" :key="i">
                                    <i class="ri-links-line text-primary"></i>
                                    <span>
                                        {{ item.text }}
                                        <a v-if="item.route && routeExists(item.route)" href="#" class="ms-1 text-nowrap" @click.prevent="goTo(item.route)">
                                            {{ t('guide.open_screen') }} <i class="ri-arrow-left-up-line align-middle"></i>
                                        </a>
                                    </span>
                                </li>
                            </ul>
                        </template>

                        <!-- Every element on the screen -->
                        <template v-else-if="activeTab === 'screen'">
                            <div v-for="(section, i) in content.sections" :key="i" class="mb-4">
                                <h6 class="fw-semibold fs-13 mb-2">{{ section.title }}</h6>
                                <dl class="guide-terms mb-0">
                                    <template v-for="(item, j) in section.items" :key="j">
                                        <dt>{{ item.name }}</dt>
                                        <dd>{{ item.desc }}</dd>
                                    </template>
                                </dl>
                            </div>
                        </template>

                        <!-- Step-by-step tasks -->
                        <template v-else-if="activeTab === 'tasks'">
                            <div v-for="(task, i) in content.tasks" :key="i" class="card custom-card shadow-none border mb-3">
                                <div class="card-body p-3">
                                    <h6 class="fw-semibold fs-13 mb-2">{{ task.title }}</h6>
                                    <ol class="guide-steps mb-0">
                                        <li v-for="(step, j) in task.steps" :key="j">{{ step }}</li>
                                    </ol>
                                </div>
                            </div>
                        </template>

                        <!-- Warnings -->
                        <template v-else-if="activeTab === 'warnings'">
                            <div v-for="(warning, i) in content.warnings" :key="i" class="alert d-flex gap-2 align-items-start mb-2" :class="warningClass(warning.level)">
                                <i :class="`${warningIcon(warning.level)} fs-16 flex-shrink-0`"></i>
                                <span>{{ warning.text }}</span>
                            </div>
                        </template>

                        <!-- Developer notes -->
                        <template v-else-if="activeTab === 'tech' && guide.tech">
                            <div v-if="decisions.length" class="mb-4">
                                <h6 class="fw-semibold fs-13 mb-2">{{ t('guide.decisions') }}</h6>
                                <div v-for="(decision, i) in decisions" :key="i" class="alert alert-warning d-flex gap-2 mb-2">
                                    <i class="ri-question-answer-line fs-16"></i><span>{{ decision }}</span>
                                </div>
                            </div>
                            <div v-for="block in techBlocks" :key="block.key" class="mb-3">
                                <h6 class="fw-semibold fs-13 mb-2">{{ t(block.label) }}</h6>
                                <ul class="list-unstyled mb-0">
                                    <li v-for="(line, i) in block.lines" :key="i" class="mb-1"><code dir="ltr" class="guide-code">{{ line }}</code></li>
                                </ul>
                            </div>
                            <p class="text-muted fs-12 mb-0">{{ t('guide.updated', { date: guide.updated }) }}</p>
                        </template>
                    </div>
                </template>

                <div class="border-top px-4 py-2 d-flex gap-2 justify-content-between align-items-center">
                    <button v-if="place" type="button" class="btn btn-sm btn-light" @click="openMap">
                        <i class="ri-road-map-line me-1 align-middle"></i>{{ t('guide.open_map') }}
                    </button>
                    <button
                        v-if="guideStore.routeName && guideStore.routeName !== route.name && routeExists(guideStore.routeName)"
                        type="button"
                        class="btn btn-sm btn-primary"
                        @click="goTo(guideStore.routeName)"
                    >
                        {{ t('guide.open_screen') }} <i class="ri-arrow-left-up-line align-middle"></i>
                    </button>
                </div>
            </div>
        </aside>
    </Teleport>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { useAdminGuideStore } from '../../../stores/adminGuide';
import { findScreenPlace, loadScreenGuide, localized } from '../../../modules/admin/guide';

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const guideStore = useAdminGuideStore();

const guide = ref(null);
const loading = ref(false);
const activeTab = ref('overview');

const place = computed(() => findScreenPlace(guideStore.routeName));
const content = computed(() => localized(guide.value, locale.value));
const screenTitle = computed(() => (place.value ? t(place.value.screen.title) : (route.meta?.title ?? guideStore.routeName ?? '')));
const decisions = computed(() => (guide.value?.tech?.decisions ?? []).map((item) => localized(item, locale.value)).filter(Boolean));

const tabs = computed(() => {
    if (! content.value) {
        return [];
    }

    const list = [
        { key: 'overview', label: 'guide.tabs.overview', icon: 'ri-compass-3-line' },
        { key: 'screen', label: 'guide.tabs.screen', icon: 'ri-layout-line' },
        { key: 'tasks', label: 'guide.tabs.tasks', icon: 'ri-list-ordered' },
        {
            key: 'warnings',
            label: 'guide.tabs.warnings',
            icon: 'ri-error-warning-line',
            count: content.value.warnings?.length ?? 0,
            badgeClass: content.value.warnings?.some((w) => w.level === 'danger') ? 'bg-danger' : 'bg-warning',
        },
    ];

    if (guide.value?.tech) {
        list.push({ key: 'tech', label: 'guide.tabs.tech', icon: 'ri-code-s-slash-line' });
    }

    return list;
});

const techBlocks = computed(() => {
    const tech = guide.value?.tech ?? {};

    return [
        { key: 'files', label: 'guide.tech_files', lines: tech.files ?? [] },
        { key: 'api', label: 'guide.tech_api', lines: tech.api ?? [] },
        { key: 'tables', label: 'guide.tech_tables', lines: tech.tables ?? [] },
        { key: 'permissions', label: 'guide.tech_permissions', lines: tech.permissions ?? [] },
    ].filter((block) => block.lines.length);
});

async function load(routeName) {
    activeTab.value = 'overview';
    loading.value = true;

    try {
        guide.value = await loadScreenGuide(routeName);
    } finally {
        loading.value = false;
    }
}

function routeExists(name) {
    return router.hasRoute(name);
}

function goTo(name) {
    router.push({ name });
}

function openMap() {
    const key = place.value?.service.key;
    guideStore.close();
    router.push({ name: 'admin.guide', params: key ? { service: key } : {} });
}

function warningClass(level) {
    return { danger: 'alert-danger', warning: 'alert-warning' }[level] ?? 'alert-info';
}

function warningIcon(level) {
    return { danger: 'ri-alarm-warning-line', warning: 'ri-error-warning-line' }[level] ?? 'ri-information-line';
}

function onKeydown(event) {
    if (event.key === 'Escape' && guideStore.isOpen) {
        guideStore.close();
    }
}

watch(() => [guideStore.isOpen, guideStore.routeName], ([isOpen, routeName]) => {
    if (isOpen) {
        load(routeName);
    }
});

// The panel follows the admin: navigating while it is open shows the new screen's guide.
watch(() => route.name, (name) => {
    if (guideStore.isOpen && name && name !== 'admin.guide') {
        guideStore.open(name);
    }
});

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<style scoped>
.screen-guide {
    width: min(560px, 100vw);
    visibility: hidden;
    transition: transform 0.25s ease-in-out, visibility 0.25s;
}

.screen-guide.show {
    visibility: visible;
    transform: none;
}

.min-w-0 {
    min-width: 0;
}

.guide-list li {
    display: flex;
    gap: 0.5rem;
    align-items: flex-start;
    margin-bottom: 0.5rem;
    line-height: 1.7;
}

.guide-list li > i {
    margin-top: 0.2rem;
    flex-shrink: 0;
}

.guide-terms dt {
    font-weight: 600;
    font-size: 0.8125rem;
}

.guide-terms dd {
    color: var(--text-muted, #6c757d);
    margin-bottom: 0.75rem;
    line-height: 1.7;
}

.guide-steps {
    padding-inline-start: 1.25rem;
}

.guide-steps li {
    margin-bottom: 0.4rem;
    line-height: 1.7;
}

.guide-code {
    white-space: pre-wrap;
    word-break: break-all;
    font-size: 0.75rem;
}
</style>
