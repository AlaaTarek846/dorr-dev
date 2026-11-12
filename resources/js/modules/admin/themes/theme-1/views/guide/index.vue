<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('guide.map_title') }}</p>
                <span class="fs-semibold text-muted">{{ t('guide.map_subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('guide.map_title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <!-- Services (one for now; each new map file adds a pill) -->
        <ul v-if="guideServices.length > 1" class="nav nav-pills mb-3 gap-2">
            <li v-for="item in guideServices" :key="item.key" class="nav-item">
                <router-link :to="{ name: 'admin.guide', params: { service: item.key } }" class="nav-link" :class="{ active: item.key === service.key }">
                    <i :class="`${item.icon} me-1 align-middle`"></i>{{ t(item.title) }}
                </router-link>
            </li>
        </ul>

        <!-- Service intro + progress + search -->
        <div class="card custom-card">
            <div class="card-body">
                <div class="row g-3 align-items-center">
                    <div class="col-lg-7">
                        <div class="d-flex gap-3 align-items-start">
                            <span class="avatar avatar-lg bg-primary-transparent flex-shrink-0"><i :class="`${service.icon} fs-22`"></i></span>
                            <div>
                                <h5 class="fw-semibold mb-1">{{ t(service.title) }}</h5>
                                <p class="text-muted mb-0 lh-lg">{{ localized(service.intro, locale) }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="d-flex justify-content-between fs-12 mb-1">
                            <span class="text-muted">{{ t('guide.progress_label') }}</span>
                            <span class="fw-semibold">{{ t('guide.progress', { documented: progress.documented, total: progress.total }) }}</span>
                        </div>
                        <div class="progress progress-sm mb-3" role="progressbar" :aria-valuenow="progressPercent" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar bg-success" :style="{ width: `${progressPercent}%` }"></div>
                        </div>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="ri-search-line"></i></span>
                            <input v-model.trim="search" type="search" class="form-control" :placeholder="t('guide.search')">
                        </div>
                        <div class="form-check form-switch mt-2 mb-0">
                            <input id="guide-only-documented" v-model="onlyDocumented" class="form-check-input" type="checkbox">
                            <label class="form-check-label fs-12" for="guide-only-documented">{{ t('guide.only_documented') }}</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="!search && !onlyDocumented" class="row g-3 mb-3">
            <!-- First run, in order -->
            <div class="col-xl-6">
                <div class="card custom-card h-100 mb-0">
                    <div class="card-header"><div class="card-title"><i class="ri-flag-line me-1"></i>{{ t('guide.first_run') }}</div></div>
                    <div class="card-body">
                        <ol class="list-unstyled mb-0 guide-first-run">
                            <li v-for="(step, i) in service.firstRun" :key="i" class="d-flex gap-3 mb-3">
                                <span class="avatar avatar-sm avatar-rounded bg-primary text-fixed-white flex-shrink-0">{{ i + 1 }}</span>
                                <div class="flex-grow-1">
                                    <p class="mb-1 lh-lg">{{ localized(step, locale) }}</p>
                                    <div v-if="step.route" class="d-flex gap-2 flex-wrap">
                                        <router-link v-if="canOpen(step.route)" :to="{ name: step.route }" class="btn btn-sm btn-primary-light">
                                            {{ screenTitle(step.route) }} <i class="ri-arrow-left-up-line align-middle"></i>
                                        </router-link>
                                        <button v-if="hasScreenGuide(step.route)" type="button" class="btn btn-sm btn-light" @click="guideStore.open(step.route)">
                                            <i class="ri-question-line me-1 align-middle"></i>{{ t('guide.read') }}
                                        </button>
                                    </div>
                                </div>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- One message's path through the groups -->
            <div class="col-xl-6">
                <div class="card custom-card h-100 mb-0">
                    <div class="card-header"><div class="card-title"><i class="ri-git-commit-line me-1"></i>{{ t('guide.flow') }}</div></div>
                    <div class="card-body">
                        <p class="text-muted fs-12">{{ t('guide.flow_hint') }}</p>
                        <div class="guide-flow">
                            <a
                                v-for="(stage, i) in service.flow"
                                :key="i"
                                href="#"
                                class="guide-flow__stage"
                                @click.prevent="scrollToGroup(stage.group)"
                            >
                                <span class="guide-flow__num">{{ i + 1 }}</span>
                                <span class="flex-grow-1">
                                    <span class="d-block fw-semibold">{{ localized(stage, locale) }}</span>
                                    <span class="d-block text-muted fs-12">{{ t(groupByKey(stage.group)?.title ?? '') }}</span>
                                </span>
                                <i class="ri-arrow-down-s-line text-muted"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Groups -->
        <div class="row g-3">
            <div v-for="group in visibleGroups" :id="`guide-group-${group.key}`" :key="group.key" class="col-xxl-4 col-lg-6">
                <div class="card custom-card h-100 mb-0" :class="{ 'guide-group--flash': flashGroup === group.key }">
                    <div class="card-header justify-content-between">
                        <div class="card-title d-flex align-items-center gap-2">
                            <i :class="`${group.icon} text-primary`"></i>{{ t(group.title) }}
                        </div>
                        <span class="badge bg-light text-default">{{ group.documented }}/{{ group.total }}</span>
                    </div>
                    <ul class="list-group list-group-flush">
                        <li v-for="screen in group.screens" :key="screen.route" class="list-group-item d-flex align-items-center gap-2">
                            <i
                                :class="hasScreenGuide(screen.route) ? 'ri-checkbox-circle-fill text-success' : 'ri-checkbox-blank-circle-line text-muted'"
                                :title="hasScreenGuide(screen.route) ? t('guide.documented') : t('guide.not_documented')"
                            ></i>
                            <span class="flex-grow-1 text-truncate">{{ t(screen.title) }}</span>
                            <button
                                v-if="hasScreenGuide(screen.route)"
                                type="button"
                                class="btn btn-sm btn-icon btn-success-light"
                                :title="t('guide.read')"
                                :aria-label="t('guide.read')"
                                @click="guideStore.open(screen.route)"
                            >
                                <i class="ri-question-line"></i>
                            </button>
                            <router-link
                                v-if="canOpen(screen.route)"
                                :to="{ name: screen.route }"
                                class="btn btn-sm btn-icon btn-primary-light"
                                :title="t('guide.open_screen')"
                                :aria-label="t('guide.open_screen')"
                            >
                                <i class="ri-arrow-left-up-line"></i>
                            </router-link>
                        </li>
                    </ul>
                </div>
            </div>

            <div v-if="!visibleGroups.length" class="col-12">
                <div class="card custom-card"><div class="card-body text-center text-muted py-5">{{ t('guide.no_results') }}</div></div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { useAdminGuideStore } from '../../../../../../stores/adminGuide';
import { usePermission } from '../../../../../../composables/usePermission';
import { findScreenPlace, findService, guideServices, hasScreenGuide, localized, serviceProgress } from '../../../../guide';

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const guideStore = useAdminGuideStore();
const { can } = usePermission();

const search = ref('');
const onlyDocumented = ref(false);
const flashGroup = ref(null);

const service = computed(() => findService(route.params.service) ?? guideServices[0]);
const progress = computed(() => serviceProgress(service.value));
const progressPercent = computed(() => (progress.value.total ? Math.round((progress.value.documented / progress.value.total) * 100) : 0));

const visibleGroups = computed(() => {
    const needle = search.value.toLowerCase();

    return service.value.groups
        .map((group) => {
            const screens = group.screens.filter((screen) => {
                if (onlyDocumented.value && ! hasScreenGuide(screen.route)) {
                    return false;
                }

                return ! needle || t(screen.title).toLowerCase().includes(needle);
            });

            return {
                ...group,
                screens,
                total: group.screens.length,
                documented: group.screens.filter((screen) => hasScreenGuide(screen.route)).length,
            };
        })
        .filter((group) => group.screens.length);
});

function groupByKey(key) {
    return service.value.groups.find((group) => group.key === key) ?? null;
}

function screenTitle(routeName) {
    const placeFound = findScreenPlace(routeName);

    return placeFound ? t(placeFound.screen.title) : routeName;
}

/** A screen link shows only when the route exists and the admin holds its permission (if it has one). */
function canOpen(routeName) {
    if (! router.hasRoute(routeName)) {
        return false;
    }

    const permission = router.resolve({ name: routeName }).meta?.permission;

    return ! permission || can(permission);
}

async function scrollToGroup(key) {
    await nextTick();
    document.getElementById(`guide-group-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    flashGroup.value = key;
    setTimeout(() => {
        flashGroup.value = null;
    }, 1600);
}
</script>

<style scoped>
.guide-flow {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.guide-flow__stage {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 0.75rem;
    border: 1px dashed var(--default-border, #e9edf6);
    border-radius: 0.5rem;
    color: inherit;
    text-decoration: none;
    transition: background-color 0.15s;
}

.guide-flow__stage:hover {
    background-color: rgba(var(--primary-rgb, 0, 110, 255), 0.06);
}

.guide-flow__num {
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-weight: 600;
    font-size: 0.8125rem;
    background-color: rgba(var(--primary-rgb, 0, 110, 255), 0.1);
    color: rgb(var(--primary-rgb, 0, 110, 255));
}

.guide-group--flash {
    box-shadow: 0 0 0 2px rgb(var(--primary-rgb, 0, 110, 255));
    transition: box-shadow 0.3s;
}
</style>
