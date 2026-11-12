<template>
    <div>
        <WalletPageHeader :title="t('freelance.quality.title')" :section="t('sidebar.freelance')" />

        <div class="alert alert-light border fs-13 d-flex align-items-center gap-2">
            <span class="flex-grow-1">{{ t('freelance.quality.hint') }}</span>
            <button type="button" class="btn btn-sm btn-light btn-wave" :disabled="summaryLoading" @click="refresh">
                <i class="ri-refresh-line me-1"></i>{{ t('freelance.quality.refresh') }}
            </button>
        </div>

        <!-- What waits in each queue, and the valid skill badges by level. -->
        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6">
                <FlStatTile icon="ri-shield-check-line" tone="success" :label="t('freelance.quality.kpi.verified_works')" :value="summaryValue('verified_works')" />
            </div>
            <div class="col-xl-3 col-md-6">
                <FlStatTile icon="ri-trophy-line" :tone="summary?.challenges_waiting ? 'warning' : 'secondary'" :label="t('freelance.quality.kpi.challenges_waiting')" :value="summaryValue('challenges_waiting')" />
            </div>
            <div class="col-xl-3 col-md-6">
                <FlStatTile icon="ri-scales-3-line" :tone="summary?.corrections_open ? 'info' : 'secondary'" :label="t('freelance.quality.kpi.corrections_open')" :value="summaryValue('corrections_open')" />
            </div>
            <div class="col-xl-3 col-md-6">
                <FlStatTile icon="ri-medal-line" tone="primary" :label="t('freelance.quality.kpi.badges')" :value="summaryLoading ? '…' : badgesTotal" :hint="levelsHint" />
            </div>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li v-for="tb in tabs" :key="tb.key" class="nav-item">
                <button type="button" class="nav-link d-flex align-items-center gap-1" :class="{ active: tab === tb.key }" @click="tab = tb.key">
                    <i :class="tb.icon"></i>{{ t(`freelance.quality.tabs.${tb.key}`) }}
                    <span v-if="tb.waiting()" class="badge bg-danger-transparent ms-1">{{ tb.waiting() }}</span>
                </button>
            </li>
        </ul>

        <!-- One list per tab: status buttons with counts, search, the rows and the pages. -->
        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-3 py-3">
                <FlStatusFilter :model-value="list.status.value" :options="filterOptions" @update:model-value="setStatus" />
                <div class="input-group input-group-sm ms-md-auto quality-search">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="list.search.value" type="search" class="form-control" :placeholder="t(`freelance.quality.search.${tab}`)">
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <!-- Verified works (FS-601) -->
                    <table v-if="tab === 'works'" class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.quality.works.work') }}</th>
                                <th>{{ t('freelance.quality.freelancer') }}</th>
                                <th>{{ t('freelance.quality.works.project') }}</th>
                                <th>{{ t('freelance.quality.works.state') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="list.loading.value" :rows="6" :layout="['two-lines', 'two-lines', 'text', 'badge', 'actions']" />
                            <tr v-else-if="!list.rows.value.length">
                                <td colspan="5" class="border-0"><FlEmpty icon="ri-shield-check-line" :title="t('freelance.quality.empty_title')" :text="t('freelance.quality.works.empty')" /></td>
                            </tr>
                            <tr v-for="row in list.rows.value" v-else :key="row.id">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-2">
                                        <img v-if="row.images?.[0]?.url" :src="row.images[0].url" alt="" class="quality-thumb rounded border">
                                        <span v-else class="avatar avatar-sm bg-light"><i class="ri-image-line text-muted"></i></span>
                                        <div class="min-w-0">
                                            <span class="fw-semibold d-block text-truncate quality-title">{{ row.title || '—' }}</span>
                                            <span class="text-muted fs-11">{{ row.specialty?.name || '' }}<template v-if="row.completed_on"> · {{ row.completed_on }}</template></span>
                                        </div>
                                    </div>
                                </td>
                                <td><PersonCell :person="row.freelancer" /></td>
                                <td><span class="d-block text-truncate quality-title">{{ row.project?.title || '—' }}</span></td>
                                <td>
                                    <span v-if="row.revoked_at" class="badge bg-danger-transparent">{{ t('freelance.quality.works.status.revoked') }}</span>
                                    <span v-else class="badge bg-success-transparent"><i class="ri-shield-check-line me-1"></i>{{ t('freelance.quality.works.status.verified') }}</span>
                                    <span v-if="row.hidden" class="badge bg-secondary-transparent ms-1">{{ t('freelance.quality.works.hidden') }}</span>
                                    <span v-if="row.revoked_at" class="d-block text-muted fs-11 text-truncate quality-title">{{ row.revoked_reason }}</span>
                                    <span v-else-if="row.verified_on" class="d-block text-muted fs-11">{{ t('freelance.quality.works.verified_on', { month: row.verified_on }) }}</span>
                                </td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-light btn-wave" @click="openWork(row)"><i class="ri-eye-line me-1"></i>{{ t('freelance.quality.view') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Practical challenges (FS-602) -->
                    <table v-else-if="tab === 'challenges'" class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.quality.freelancer') }}</th>
                                <th>{{ t('freelance.quality.challenges.skill') }}</th>
                                <th>{{ t('freelance.quality.challenges.target') }}</th>
                                <th>{{ t('freelance.quality.sent') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="list.loading.value" :rows="6" :layout="['two-lines', 'text', 'badge', 'text', 'badge', 'actions']" />
                            <tr v-else-if="!list.rows.value.length">
                                <td colspan="6" class="border-0"><FlEmpty icon="ri-trophy-line" :title="t('freelance.quality.empty_title')" :text="t('freelance.quality.challenges.empty')" /></td>
                            </tr>
                            <tr v-for="row in list.rows.value" v-else :key="row.uuid">
                                <td class="ps-4"><PersonCell :person="row.freelancer" /></td>
                                <td>
                                    <span class="d-block fw-semibold">{{ row.skill?.name || '—' }}</span>
                                    <span v-if="row.badge" class="text-muted fs-11">{{ t('freelance.quality.challenges.now', { level: levelName(row.badge.level) }) }}</span>
                                </td>
                                <td><span class="badge" :class="levelBadge(row.target_level)">{{ levelName(row.target_level) }}</span></td>
                                <td class="fs-12">{{ formatCatalogDate(row.sent_at, locale) }}</td>
                                <td>
                                    <span class="badge" :class="statusBadge(row.status)">{{ t(`freelance.quality.challenges.status.${row.status}`) }}</span>
                                    <span v-if="row.status === 'accepted' && row.granted_level" class="d-block text-muted fs-11">{{ t('freelance.quality.challenges.granted', { level: levelName(row.granted_level) }) }}</span>
                                </td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-wave" :class="canDecideRow(row) ? 'btn-primary' : 'btn-light'" @click="openChallenge(row)">
                                        <i :class="canDecideRow(row) ? 'ri-scales-3-line' : 'ri-eye-line'" class="me-1"></i>{{ canDecideRow(row) ? t('freelance.quality.decide') : t('freelance.quality.view') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Reputation corrections (FS-604) -->
                    <table v-else class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.quality.freelancer') }}</th>
                                <th>{{ t('freelance.quality.corrections.project') }}</th>
                                <th>{{ t('freelance.quality.corrections.indicator') }}</th>
                                <th>{{ t('freelance.quality.corrections.reason') }}</th>
                                <th>{{ t('freelance.quality.sent') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="list.loading.value" :rows="6" :layout="['two-lines', 'text', 'badge', 'text', 'text', 'badge', 'actions']" />
                            <tr v-else-if="!list.rows.value.length">
                                <td colspan="7" class="border-0"><FlEmpty icon="ri-scales-3-line" :title="t('freelance.quality.empty_title')" :text="t('freelance.quality.corrections.empty')" /></td>
                            </tr>
                            <tr v-for="row in list.rows.value" v-else :key="row.uuid">
                                <td class="ps-4"><PersonCell :person="row.freelancer" /></td>
                                <td><span class="d-block text-truncate quality-title">{{ row.project?.title || '—' }}</span></td>
                                <td><span class="badge bg-primary-transparent">{{ t(`freelance.quality.corrections.indicators.${row.indicator}`) }}</span></td>
                                <td>{{ t(`freelance.quality.corrections.reasons.${row.reason}`) }}</td>
                                <td class="fs-12">{{ formatCatalogDate(row.sent_at, locale) }}</td>
                                <td><span class="badge" :class="statusBadge(row.status)">{{ t(`freelance.quality.corrections.status.${row.status}`) }}</span></td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-wave" :class="canDecideRow(row) ? 'btn-primary' : 'btn-light'" @click="openCorrection(row)">
                                        <i :class="canDecideRow(row) ? 'ri-scales-3-line' : 'ri-eye-line'" class="me-1"></i>{{ canDecideRow(row) ? t('freelance.quality.decide') : t('freelance.quality.view') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="!list.loading.value && list.rows.value.length" class="card-footer border-top-0 d-flex align-items-center flex-wrap gap-3">
                <div class="text-muted fs-13">{{ t('countries.showing_entries', { from: list.pagination.value.from ?? 0, to: list.pagination.value.to ?? 0, total: list.pagination.value.total ?? 0 }) }}</div>
                <nav v-if="(list.pagination.value.last_page ?? 1) > 1" class="pagination-style-4 ms-md-auto">
                    <ul class="pagination mb-0">
                        <li class="page-item" :class="{ disabled: list.page.value <= 1 }"><button type="button" class="page-link" @click="list.page.value -= 1">{{ t('countries.previous') }}</button></li>
                        <li class="page-item active"><span class="page-link">{{ list.page.value }}</span></li>
                        <li class="page-item" :class="{ disabled: list.page.value >= list.pagination.value.last_page }"><button type="button" class="page-link text-primary" @click="list.page.value += 1">{{ t('countries.next') }}</button></li>
                    </ul>
                </nav>
            </div>
        </div>

        <!-- One verified work: everything the client sees, and taking the badge back (with the reason the freelancer reads) or giving it back. -->
        <WalletModal :show="!!work" :title="t('freelance.quality.works.details')" size="lg" smooth @close="work = null">
            <div v-if="work">
                <div class="d-flex align-items-start gap-3 mb-3 flex-wrap">
                    <div class="min-w-0">
                        <div class="fw-semibold fs-15">{{ work.title }}</div>
                        <div class="text-muted fs-12">
                            {{ t('freelance.quality.freelancer') }}: {{ work.freelancer?.name || '—' }} <span v-if="work.freelancer?.phone" dir="ltr">({{ work.freelancer.phone }})</span>
                        </div>
                        <div class="text-muted fs-12">{{ t('freelance.quality.works.project') }}: {{ work.project?.title || '—' }}</div>
                    </div>
                    <div class="ms-auto text-end">
                        <span v-if="work.revoked_at" class="badge bg-danger-transparent">{{ t('freelance.quality.works.status.revoked') }}</span>
                        <span v-else class="badge bg-success-transparent"><i class="ri-shield-check-line me-1"></i>{{ t('freelance.quality.works.status.verified') }}</span>
                        <div class="fs-11 text-muted mt-1">{{ t(`freelance.quality.works.visibility.${work.visibility}`) }}<template v-if="work.hidden"> · {{ t('freelance.quality.works.hidden') }}</template></div>
                    </div>
                </div>

                <div class="row g-2 fs-12 mb-3">
                    <div class="col-sm-6"><span class="text-muted">{{ t('freelance.quality.works.specialty') }}:</span> {{ work.specialty?.name || '—' }}</div>
                    <div class="col-sm-6"><span class="text-muted">{{ t('freelance.quality.works.completed') }}:</span> {{ work.completed_on || '—' }}</div>
                    <div class="col-sm-6"><span class="text-muted">{{ t('freelance.quality.works.verified_month') }}:</span> {{ work.verified_on || '—' }}</div>
                    <div class="col-12">
                        <span class="text-muted">{{ t('freelance.quality.works.skills') }}:</span>
                        <span v-if="!work.skills?.length"> —</span>
                        <span v-for="s in work.skills" :key="s.id" class="badge bg-light text-default ms-1">{{ s.name }}</span>
                    </div>
                </div>

                <div v-if="work.description" class="border rounded p-3 mb-3 fs-13 quality-pre">{{ work.description }}</div>

                <div v-if="work.case_study" class="border rounded p-3 mb-3">
                    <h6 class="fs-13 fw-semibold">{{ t('freelance.quality.works.case_study') }}</h6>
                    <div v-for="(text, key) in work.case_study" :key="key" class="fs-12 mb-2">
                        <span class="fw-semibold d-block">{{ t(`freelance.quality.works.case.${key}`) }}</span>
                        <span class="quality-pre">{{ text }}</span>
                    </div>
                </div>

                <div v-if="work.images?.length" class="mb-3">
                    <h6 class="fs-13 fw-semibold">{{ t('freelance.quality.works.images') }}</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <a v-for="img in work.images" :key="img.id ?? img.url" :href="safeUrl(img.url)" target="_blank" rel="noopener noreferrer">
                            <img :src="img.url" :alt="img.name || ''" class="quality-image rounded border">
                        </a>
                    </div>
                    <div class="text-muted fs-11 mt-1">{{ t('freelance.quality.works.images_expire') }}</div>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <a v-if="safeUrl(work.external_url)" :href="safeUrl(work.external_url)" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">
                        <i class="ri-external-link-line me-1"></i>{{ t('freelance.quality.works.external') }}
                    </a>
                    <a v-if="safeUrl(work.video?.embed_url)" :href="safeUrl(work.video.embed_url)" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">
                        <i class="ri-play-circle-line me-1"></i>{{ t('freelance.quality.works.video') }} <span class="text-muted">({{ work.video.provider }})</span>
                    </a>
                </div>

                <div v-if="work.revoked_at" class="alert alert-danger fs-13">
                    <div class="fw-semibold">{{ t('freelance.quality.works.revoked_title', { date: formatCatalogDate(work.revoked_at, locale) }) }}</div>
                    <div class="quality-pre">{{ work.revoked_reason }}</div>
                </div>

                <div v-if="canUpdate && !work.revoked_at" class="border rounded p-3">
                    <h6 class="fs-13 fw-semibold">{{ t('freelance.quality.works.unverify') }}</h6>
                    <label class="form-label fs-12">{{ t('freelance.quality.works.reason') }} <span class="text-danger">*</span></label>
                    <textarea v-model="reason" rows="2" maxlength="255" class="form-control" :placeholder="t('freelance.quality.works.reason_hint')"></textarea>
                    <div class="d-flex justify-content-between text-muted fs-11 mt-1">
                        <span>{{ t('freelance.quality.works.unverify_hint') }}</span>
                        <span dir="ltr">{{ reason.length }}/255</span>
                    </div>
                </div>
                <div v-else-if="canUpdate && work.revoked_at" class="text-muted fs-12">{{ t('freelance.quality.works.reverify_hint') }}</div>
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" @click="work = null">{{ t('cancel') }}</button>
                <template v-if="canUpdate && work">
                    <button v-if="!work.revoked_at" type="button" class="btn btn-danger" :disabled="working || reason.trim().length < 3" @click="unverify">
                        <span v-if="working" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-shield-cross-line me-1"></i>{{ t('freelance.quality.works.unverify') }}
                    </button>
                    <button v-else type="button" class="btn btn-success" :disabled="working" @click="reverify">
                        <span v-if="working" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-shield-check-line me-1"></i>{{ t('freelance.quality.works.reverify') }}
                    </button>
                </template>
            </template>
        </WalletModal>

        <!-- One practical challenge: the brief, what was built (the link) and the freelancer's word, the badge it builds on; then the decision. -->
        <WalletModal :show="!!challenge" :title="t('freelance.quality.challenges.details')" size="lg" smooth @close="challenge = null">
            <div v-if="challenge">
                <div class="d-flex align-items-start gap-3 mb-3 flex-wrap">
                    <div class="min-w-0">
                        <div class="fw-semibold fs-15">{{ challenge.skill?.name }}</div>
                        <div class="text-muted fs-12">
                            {{ t('freelance.quality.freelancer') }}: {{ challenge.freelancer?.name || '—' }} <span v-if="challenge.freelancer?.phone" dir="ltr">({{ challenge.freelancer.phone }})</span>
                        </div>
                        <div class="text-muted fs-12">{{ t('freelance.quality.sent') }}: {{ formatCatalogDate(challenge.sent_at, locale) }}</div>
                    </div>
                    <div class="ms-auto text-end">
                        <span class="badge" :class="statusBadge(challenge.status)">{{ t(`freelance.quality.challenges.status.${challenge.status}`) }}</span>
                        <div class="fs-12 mt-1">{{ t('freelance.quality.challenges.target') }}: <span class="badge" :class="levelBadge(challenge.target_level)">{{ levelName(challenge.target_level) }}</span></div>
                    </div>
                </div>

                <div class="border rounded p-3 mb-3 fs-12">
                    <h6 class="fs-13 fw-semibold">{{ t('freelance.quality.challenges.current_badge') }}</h6>
                    <div v-if="!challenge.badge" class="text-danger">{{ t('freelance.quality.challenges.badge_none') }}</div>
                    <div v-else class="d-flex flex-wrap gap-3">
                        <span><span class="text-muted">{{ t('freelance.quality.challenges.level') }}:</span> <span class="badge" :class="levelBadge(challenge.badge.level)">{{ levelName(challenge.badge.level) }}</span></span>
                        <span><span class="text-muted">{{ t('freelance.quality.challenges.score') }}:</span> <span dir="ltr">{{ challenge.badge.score_percent }}%</span></span>
                        <span><span class="text-muted">{{ t('freelance.quality.challenges.expires') }}:</span> {{ challenge.badge.expires_at ? formatCatalogDate(challenge.badge.expires_at, locale) : t('freelance.skill_tests.never') }}</span>
                        <span class="badge" :class="challenge.badge.valid ? 'bg-success-transparent' : 'bg-danger-transparent'">{{ challenge.badge.valid ? t('freelance.skill_tests.valid') : t('freelance.skill_tests.ended') }}</span>
                    </div>
                </div>

                <div class="border rounded p-3 mb-3">
                    <h6 class="fs-13 fw-semibold">{{ t('freelance.quality.challenges.brief') }}</h6>
                    <div v-if="challenge.brief" class="fs-13 quality-pre">{{ challenge.brief }}</div>
                    <div v-else class="text-muted fs-12">{{ t('freelance.quality.challenges.no_brief') }}</div>
                </div>

                <div class="border rounded p-3 mb-3">
                    <h6 class="fs-13 fw-semibold">{{ t('freelance.quality.challenges.submission') }}</h6>
                    <a v-if="safeUrl(challenge.link)" :href="safeUrl(challenge.link)" target="_blank" rel="noopener noreferrer" class="fs-13 d-inline-block text-break mb-2" dir="ltr">
                        <i class="ri-external-link-line me-1"></i>{{ challenge.link }}
                    </a>
                    <div v-else class="fs-13 text-break mb-2" dir="ltr">{{ challenge.link || '—' }}</div>
                    <div v-if="challenge.note" class="fs-13 quality-pre"><span class="text-muted">{{ t('freelance.quality.challenges.their_note') }}:</span> {{ challenge.note }}</div>
                    <div class="text-warning fs-11 mt-2"><i class="ri-error-warning-line me-1"></i>{{ t('freelance.quality.challenges.link_warning') }}</div>
                </div>

                <div v-if="challenge.status !== 'submitted' && challenge.decided_at" class="alert fs-13" :class="challenge.status === 'accepted' ? 'alert-success' : 'alert-secondary'">
                    <div class="fw-semibold">
                        {{ t(`freelance.quality.challenges.status.${challenge.status}`) }} · {{ formatCatalogDate(challenge.decided_at, locale) }}
                        <template v-if="challenge.granted_level"> · {{ t('freelance.quality.challenges.granted', { level: levelName(challenge.granted_level) }) }}</template>
                    </div>
                    <div v-if="challenge.decision_note" class="quality-pre">{{ challenge.decision_note }}</div>
                </div>

                <div v-if="canDecideRow(challenge)" class="border rounded p-3">
                    <h6 class="fs-13 fw-semibold">{{ t('freelance.quality.decision') }}</h6>
                    <div class="d-flex gap-2 mb-3">
                        <button type="button" class="btn btn-sm flex-fill" :class="form.decision === 'accept' ? 'btn-success' : 'btn-outline-success'" @click="form.decision = 'accept'"><i class="ri-check-line me-1"></i>{{ t('freelance.quality.accept') }}</button>
                        <button type="button" class="btn btn-sm flex-fill" :class="form.decision === 'reject' ? 'btn-danger' : 'btn-outline-danger'" @click="form.decision = 'reject'"><i class="ri-close-line me-1"></i>{{ t('freelance.quality.reject') }}</button>
                    </div>
                    <div v-if="form.decision === 'accept'" class="mb-3">
                        <label class="form-label fs-12">{{ t('freelance.quality.challenges.grant_level') }}</label>
                        <div class="d-flex gap-2">
                            <button v-for="lv in grantable" :key="lv" type="button" class="btn btn-sm" :class="form.level === lv ? 'btn-primary' : 'btn-outline-primary'" @click="form.level = lv">{{ levelName(lv) }}</button>
                        </div>
                        <div class="text-muted fs-11 mt-1">{{ t('freelance.quality.challenges.grant_hint') }}</div>
                    </div>
                    <label class="form-label fs-12">{{ t('freelance.quality.note') }} <span v-if="form.decision === 'reject'" class="text-danger">*</span><span v-else class="text-muted">({{ t('freelance.quality.optional') }})</span></label>
                    <textarea v-model="form.note" rows="3" maxlength="1000" class="form-control" :placeholder="form.decision === 'reject' ? t('freelance.quality.challenges.reject_hint') : t('freelance.quality.note_hint')"></textarea>
                    <div class="text-muted fs-11 mt-1 text-end" dir="ltr">{{ form.note.length }}/1000</div>
                </div>
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" @click="challenge = null">{{ t('cancel') }}</button>
                <button v-if="challenge && canDecideRow(challenge)" type="button" class="btn" :class="form.decision === 'reject' ? 'btn-danger' : 'btn-primary'" :disabled="working || !ready" @click="decideChallenge">
                    <span v-if="working" class="spinner-border spinner-border-sm me-1"></span>{{ t('freelance.quality.confirm') }}
                </button>
            </template>
        </WalletModal>

        <!-- One reputation correction: which figure, which project, why; accepting takes the project out of that figure. -->
        <WalletModal :show="!!correction" :title="t('freelance.quality.corrections.details')" size="lg" @close="correction = null">
            <div v-if="correction">
                <div class="d-flex align-items-start gap-3 mb-3 flex-wrap">
                    <div class="min-w-0">
                        <div class="fw-semibold fs-15">{{ correction.project?.title || '—' }}</div>
                        <div class="text-muted fs-12">
                            {{ t('freelance.quality.freelancer') }}: {{ correction.freelancer?.name || '—' }} <span v-if="correction.freelancer?.phone" dir="ltr">({{ correction.freelancer.phone }})</span>
                        </div>
                        <div class="text-muted fs-12">{{ t('freelance.quality.sent') }}: {{ formatCatalogDate(correction.sent_at, locale) }}</div>
                    </div>
                    <span class="badge ms-auto" :class="statusBadge(correction.status)">{{ t(`freelance.quality.corrections.status.${correction.status}`) }}</span>
                </div>

                <div class="border rounded p-3 mb-3 fs-13">
                    <div><span class="text-muted">{{ t('freelance.quality.corrections.indicator') }}:</span> <span class="badge bg-primary-transparent">{{ t(`freelance.quality.corrections.indicators.${correction.indicator}`) }}</span></div>
                    <div class="mt-1"><span class="text-muted">{{ t('freelance.quality.corrections.reason') }}:</span> {{ t(`freelance.quality.corrections.reasons.${correction.reason}`) }}</div>
                    <div v-if="correction.note" class="mt-2 quality-pre"><span class="text-muted">{{ t('freelance.quality.corrections.their_note') }}:</span> {{ correction.note }}</div>
                </div>

                <div v-if="correction.status !== 'open'" class="alert fs-13" :class="correction.status === 'accepted' ? 'alert-success' : 'alert-secondary'">
                    <div class="fw-semibold">{{ t(`freelance.quality.corrections.status.${correction.status}`) }} · {{ formatCatalogDate(correction.decided_at, locale) }}</div>
                    <div v-if="correction.decision_note" class="quality-pre">{{ correction.decision_note }}</div>
                </div>

                <div v-if="canDecideRow(correction)" class="border rounded p-3">
                    <h6 class="fs-13 fw-semibold">{{ t('freelance.quality.decision') }}</h6>
                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-sm flex-fill" :class="form.decision === 'accept' ? 'btn-success' : 'btn-outline-success'" @click="form.decision = 'accept'"><i class="ri-check-line me-1"></i>{{ t('freelance.quality.accept') }}</button>
                        <button type="button" class="btn btn-sm flex-fill" :class="form.decision === 'reject' ? 'btn-danger' : 'btn-outline-danger'" @click="form.decision = 'reject'"><i class="ri-close-line me-1"></i>{{ t('freelance.quality.reject') }}</button>
                    </div>
                    <div v-if="form.decision === 'accept'" class="text-info fs-12 mb-2">
                        <i class="ri-information-line me-1"></i>{{ t('freelance.quality.corrections.accept_effect', { indicator: t(`freelance.quality.corrections.indicators.${correction.indicator}`) }) }}
                    </div>
                    <label class="form-label fs-12">{{ t('freelance.quality.note') }} <span v-if="form.decision === 'reject'" class="text-danger">*</span><span v-else class="text-muted">({{ t('freelance.quality.optional') }})</span></label>
                    <textarea v-model="form.note" rows="3" maxlength="1000" class="form-control" :placeholder="form.decision === 'reject' ? t('freelance.quality.corrections.reject_hint') : t('freelance.quality.note_hint')"></textarea>
                    <div class="text-muted fs-11 mt-1 text-end" dir="ltr">{{ form.note.length }}/1000</div>
                </div>
            </div>
            <template #footer>
                <button type="button" class="btn btn-light" @click="correction = null">{{ t('cancel') }}</button>
                <button v-if="correction && canDecideRow(correction)" type="button" class="btn" :class="form.decision === 'reject' ? 'btn-danger' : 'btn-primary'" :disabled="working || !ready" @click="decideCorrection">
                    <span v-if="working" class="spinner-border spinner-border-sm me-1"></span>{{ t('freelance.quality.confirm') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import FlEmpty from '../../../../../../../components/freelance/FlEmpty.vue';
import FlStatTile from '../../../../../../../components/freelance/FlStatTile.vue';
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import { useCatalogPermissions } from '../../../../../../../composables/useCatalogPermissions';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { formatCatalogDate } from '../../../../../../../utils/catalog';

// The quality desk (FS-605): the works «Verified by Dorr» (FS-601) and taking that badge back, the practical challenges that raise a
// skill badge to advanced / expert (FS-602), and the reputation figures a freelancer asked to correct (FS-604). Permission
// `freelance-quality` (view to read, update to decide). The skill test settings that feed the challenges live on the skill tests screen.
const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();
const { canUpdate } = useCatalogPermissions('freelance-quality');
const base = '/api/admin/v1/freelance-quality';

const LEVELS = ['beginner', 'intermediate', 'advanced', 'expert'];
const levelName = (lv) => (LEVELS.includes(lv) ? t(`freelance.quality.levels.${lv}`) : (lv || '—'));
const levelBadge = (lv) => ({ beginner: 'bg-secondary-transparent', intermediate: 'bg-info-transparent', advanced: 'bg-primary-transparent', expert: 'bg-warning-transparent' }[lv] ?? 'bg-light text-default');
const statusBadge = (s) => ({
    submitted: 'bg-warning-transparent', open: 'bg-warning-transparent', accepted: 'bg-success-transparent', rejected: 'bg-danger-transparent', withdrawn: 'bg-secondary-transparent',
}[s] ?? 'bg-light text-default');
// Links come from freelancers: only http(s) is ever opened.
const safeUrl = (u) => (typeof u === 'string' && /^https?:\/\//i.test(u.trim()) ? u.trim() : null);

/** A freelancer's name with their phone under it. */
const PersonCell = defineComponent({
    props: { person: { type: Object, default: null } },
    setup: (props) => () => h('div', [
        h('span', { class: 'fw-semibold d-block' }, props.person?.name || '—'),
        props.person?.phone ? h('span', { class: 'text-muted fs-11', dir: 'ltr' }, props.person.phone) : null,
    ]),
});

// ---- summary
const summary = ref(null);
const summaryLoading = ref(false);
const summaryValue = (key) => (summaryLoading.value && !summary.value ? '…' : (summary.value?.[key] ?? 0));
const levels = computed(() => (summary.value?.levels && !Array.isArray(summary.value.levels) ? summary.value.levels : {}));
const badgesTotal = computed(() => LEVELS.reduce((sum, lv) => sum + Number(levels.value[lv] ?? 0), 0));
const levelsHint = computed(() => LEVELS.map((lv) => `${levelName(lv)} ${levels.value[lv] ?? 0}`).join(' · '));

async function loadSummary() {
    summaryLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/summary`);
        summary.value = data.data ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        summaryLoading.value = false;
    }
}

// ---- the three lists, each with its own status, search and page
function makeList(path, firstStatus) {
    const l = {
        path,
        rows: ref([]),
        counts: ref({}),
        pagination: ref({}),
        loading: ref(false),
        status: ref(firstStatus),
        search: ref(''),
        page: ref(1),
        loaded: false,
    };
    l.load = async () => {
        l.loading.value = true;
        try {
            const { data } = await adminAxios.get(`${base}/${path}`, {
                params: { page: l.page.value, per_page: 15, status: l.status.value === 'all' ? undefined : l.status.value, search: l.search.value.trim() || undefined },
            });
            l.rows.value = data.data ?? [];
            l.pagination.value = data.pagination ?? {};
            l.counts.value = data.pagination?.counts ?? {};
            l.loaded = true;
        } catch (error) {
            showError(extractApiErrorMessage(error, t('toast.error')));
        } finally {
            l.loading.value = false;
        }
    };
    const reload = () => (l.page.value === 1 ? l.load() : (l.page.value = 1));
    let timer = null;
    watch(l.search, () => { clearTimeout(timer); timer = setTimeout(reload, 300); });
    watch(l.status, reload);
    watch(l.page, l.load);

    return l;
}

const lists = {
    works: makeList('verified-works', 'verified'),
    challenges: makeList('challenges', 'submitted'),
    corrections: makeList('corrections', 'open'),
};
const statusesOf = {
    works: [['verified', 'active'], ['revoked', 'deleted']],
    challenges: [['submitted', 'pending'], ['accepted', 'active'], ['rejected', 'deleted'], ['withdrawn', 'inactive'], ['all', 'all']],
    corrections: [['open', 'pending'], ['accepted', 'active'], ['rejected', 'deleted'], ['all', 'all']],
};

const tab = ref('works');
const list = computed(() => lists[tab.value]);
const tabs = [
    { key: 'works', icon: 'ri-shield-check-line', waiting: () => 0 },
    { key: 'challenges', icon: 'ri-trophy-line', waiting: () => summary.value?.challenges_waiting ?? 0 },
    { key: 'corrections', icon: 'ri-scales-3-line', waiting: () => summary.value?.corrections_open ?? 0 },
];
const filterOptions = computed(() => statusesOf[tab.value].map(([value, tone]) => ({
    value,
    tone,
    label: value === 'all' ? t('countries.filter_all') : t(`freelance.quality.${tab.value}.status.${value}`),
    count: list.value.counts.value[value] ?? 0,
})));
const setStatus = (s) => { list.value.status.value = s; };

watch(tab, (v) => { if (!lists[v].loaded) lists[v].load(); });

function refresh() {
    loadSummary();
    list.value.load();
}

// ---- decisions
const work = ref(null);
const challenge = ref(null);
const correction = ref(null);
const reason = ref('');
const working = ref(false);
const form = reactive({ decision: null, level: null, note: '' });

const canDecideRow = (row) => canUpdate.value && (row.status === 'submitted' || row.status === 'open');
const grantable = computed(() => (challenge.value?.target_level === 'expert' ? ['advanced', 'expert'] : ['advanced']));
const ready = computed(() => !!form.decision && (form.decision !== 'reject' || form.note.trim().length > 0));

function resetForm(level = null) {
    Object.assign(form, { decision: null, level, note: '' });
}

function openWork(row) {
    work.value = row;
    reason.value = '';
}

function openChallenge(row) {
    challenge.value = row;
    resetForm(row.target_level);
}

function openCorrection(row) {
    correction.value = row;
    resetForm();
}

async function act(request, after) {
    working.value = true;
    try {
        const response = await request();
        showSuccess(extractApiMessage(response, t('freelance.quality.saved')));
        after(response.data.data);
        loadSummary();
        list.value.load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
        // Someone else decided it meanwhile: show the list as it is now.
        if (error?.response?.status === 409) {
            list.value.load();
            loadSummary();
        }
    } finally {
        working.value = false;
    }
}

function unverify() {
    if (!window.confirm(t('freelance.quality.works.unverify_confirm', { title: work.value.title }))) return;
    act(() => adminAxios.post(`${base}/verified-works/${work.value.id}/unverify`, { reason: reason.value.trim() }), () => { work.value = null; });
}

function reverify() {
    if (!window.confirm(t('freelance.quality.works.reverify_confirm', { title: work.value.title }))) return;
    act(() => adminAxios.post(`${base}/verified-works/${work.value.id}/reverify`), () => { work.value = null; });
}

function decideChallenge() {
    const text = form.decision === 'accept'
        ? t('freelance.quality.challenges.accept_confirm', { name: challenge.value.freelancer?.name ?? '', level: levelName(form.level) })
        : t('freelance.quality.challenges.reject_confirm', { name: challenge.value.freelancer?.name ?? '' });
    if (!window.confirm(text)) return;
    const body = { decision: form.decision, note: form.note.trim() || undefined, ...(form.decision === 'accept' ? { level: form.level } : {}) };
    act(() => adminAxios.post(`${base}/challenges/${challenge.value.uuid}/decide`, body), (fresh) => { challenge.value = fresh ? { ...challenge.value, ...fresh } : null; });
}

function decideCorrection() {
    const text = form.decision === 'accept'
        ? t('freelance.quality.corrections.accept_confirm', { indicator: t(`freelance.quality.corrections.indicators.${correction.value.indicator}`) })
        : t('freelance.quality.corrections.reject_confirm');
    if (!window.confirm(text)) return;
    act(() => adminAxios.post(`${base}/corrections/${correction.value.uuid}/decide`, { decision: form.decision, note: form.note.trim() || undefined }), (fresh) => { correction.value = fresh ? { ...correction.value, ...fresh } : null; });
}

onMounted(() => {
    loadSummary();
    lists.works.load();
});
</script>

<style scoped>
.quality-title {
    max-width: 260px;
}

.quality-search {
    max-width: 280px;
}

.quality-thumb {
    width: 40px;
    height: 40px;
    object-fit: cover;
}

.quality-image {
    width: 120px;
    height: 90px;
    object-fit: cover;
}

.quality-pre {
    white-space: pre-line;
}
</style>
