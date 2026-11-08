<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.plans.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.plans.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border fs-13">{{ isTeam ? t('freelance.plans.team_hint') : t('freelance.plans.hint') }}</div>

        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6">
                <FlStatTile
                    icon="ri-vip-crown-2-line"
                    tone="primary"
                    :label="isTeam ? t('freelance.plans.team_subscribers') : t('freelance.plans.subscribers')"
                    :value="loading ? '…' : (isTeam ? overview.team_subscribers : overview.subscribers)"
                />
            </div>
            <div class="col-xl-3 col-md-6">
                <FlStatTile icon="ri-user-add-line" tone="info" :label="t('freelance.plans.trialing')" :value="loading ? '…' : (subCounts.trialing ?? 0)" />
            </div>
            <div class="col-xl-3 col-md-6">
                <FlStatTile icon="ri-error-warning-line" :tone="(subCounts.past_due ?? 0) ? 'warning' : 'secondary'" :label="t('freelance.plans.past_due')" :value="loading ? '…' : (subCounts.past_due ?? 0)" />
            </div>
            <div class="col-xl-3 col-md-6">
                <FlStatTile
                    icon="ri-money-dollar-circle-line"
                    tone="success"
                    :label="t('freelance.plans.revenue_30d')"
                    :value="loading ? '…' : (revenueText || '—')"
                />
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-3 py-3">
                <div class="btn-group btn-group-sm" role="group" :aria-label="t('freelance.plans.title')">
                    <button
                        v-for="a in audienceOptions"
                        :key="a.value"
                        type="button"
                        class="btn"
                        :class="audience === a.value ? 'btn-primary' : 'btn-outline-primary'"
                        :aria-pressed="audience === a.value"
                        @click="audience = a.value"
                    >
                        <i :class="[a.icon, 'me-1']"></i>{{ a.label }}
                    </button>
                </div>
                <FlStatusFilter v-model="tab" :options="tabOptions" />
            </div>

            <!-- The plans: what each one gives. -->
            <div v-if="tab === 'plans'" class="card-body">
                <div v-if="loading" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm"></span></div>
                <div v-else class="row g-3">
                    <div v-if="!shownPlans.length" class="col-12 text-center text-muted py-5">{{ t('wallet.common.empty') }}</div>
                    <div v-for="p in shownPlans" :key="p.id" class="col-xl-4 col-md-6">
                        <div class="border rounded-3 p-3 h-100 d-flex flex-column">
                            <div class="d-flex align-items-start gap-2 mb-2">
                                <div class="flex-grow-1">
                                    <span class="d-block fw-semibold fs-16">{{ planName(p) }}</span>
                                    <code class="fs-11 text-muted">{{ p.slug }}</code>
                                </div>
                                <span v-if="p.free" class="badge bg-secondary-transparent">{{ t('freelance.plans.free_badge') }}</span>
                                <span v-else class="badge" :class="p.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ p.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                            </div>
                            <p v-if="descriptionOf(p)" class="text-muted fs-12 mb-2">{{ descriptionOf(p) }}</p>
                            <!-- A team plan: the room it opens for the whole team (never per member). -->
                            <ul v-if="p.audience === 'team'" class="list-unstyled fs-13 mb-3 flex-grow-1">
                                <li class="mb-1"><i class="ri-team-line me-2 text-primary"></i>{{ p.team_members_max == null ? t('freelance.plans.team_members_unlimited') : t('freelance.plans.team_members_n', { n: p.team_members_max }) }}</li>
                                <li class="mb-1"><i class="ri-folder-chart-line me-2 text-primary"></i>{{ p.team_projects_max == null ? t('freelance.plans.team_projects_unlimited') : t('freelance.plans.team_projects_n', { n: p.team_projects_max }) }}</li>
                                <li class="mb-1"><i class="ri-send-plane-line me-2 text-primary"></i>{{ teamOffersText(p) }}</li>
                                <li class="mb-1"><i class="ri-gift-line me-2 text-primary"></i>{{ t('freelance.plans.trial_days', { n: p.trial_days }) }}</li>
                                <li class="mb-1"><i class="ri-group-line me-2 text-primary"></i>{{ t('freelance.plans.on_plan_teams', { n: p.subscribers }) }}</li>
                            </ul>
                            <ul v-else class="list-unstyled fs-13 mb-3 flex-grow-1">
                                <li class="mb-1"><i class="ri-send-plane-line me-2 text-primary"></i>{{ offersText(p) }}</li>
                                <li class="mb-1"><i class="ri-percent-line me-2 text-primary"></i>{{ commissionText(p) }}</li>
                                <li v-if="!p.free" class="mb-1"><i class="ri-gift-line me-2 text-primary"></i>{{ t('freelance.plans.trial_days', { n: p.trial_days }) }}</li>
                                <li v-if="!p.free" class="mb-1"><i class="ri-medal-line me-2 text-primary"></i>{{ perksText(p) }}</li>
                                <li class="mb-1"><i class="ri-group-line me-2 text-primary"></i>{{ t('freelance.plans.on_plan', { n: p.subscribers }) }}</li>
                            </ul>
                            <div v-if="p.audience === 'team' && !p.status" class="alert alert-warning py-2 px-3 fs-12 mb-3">{{ t('freelance.plans.team_not_sold') }}</div>
                            <div v-if="canUpdate && !p.free" class="text-end">
                                <button type="button" class="btn btn-sm btn-light btn-wave" @click="openEdit(p)"><i class="ri-edit-line me-1"></i>{{ t('freelance.plans.edit') }}</button>
                            </div>
                            <div v-else-if="p.free" class="text-muted fs-11">{{ t('freelance.plans.free_note') }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Prices: every country, the monthly and the yearly. -->
            <template v-else-if="tab === 'prices'">
                <div class="card-body border-bottom py-3 d-flex align-items-center flex-wrap gap-2">
                    <Select v-model="pricePlanId" :options="paidPlanOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="priceSearch" type="search" class="form-control" :placeholder="t('freelance.plans.search_country')">
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input id="plans-missing" v-model="onlyMissing" class="form-check-input" type="checkbox">
                        <label class="form-check-label fs-13" for="plans-missing">{{ t('freelance.plans.only_missing') }}</label>
                    </div>
                    <button v-if="canUpdate" type="button" class="btn btn-outline-primary btn-sm btn-wave ms-auto" :disabled="filling" @click="fillMissing">
                        <span v-if="filling" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-magic-line me-1"></i>{{ t('freelance.plans.fill') }}
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">{{ t('freelance.freelancers.country') }}</th>
                                    <th>{{ t('freelance.plans.monthly') }}</th>
                                    <th>{{ t('freelance.plans.yearly') }}</th>
                                    <th>{{ t('freelance.plans.year_saving') }}</th>
                                    <th class="text-end pe-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <TableSkeleton v-if="priceLoading" :rows="6" :layout="['two-lines', 'text', 'text', 'text', 'actions']" />
                                <tr v-else-if="!prices.length"><td colspan="5" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                                <tr v-for="c in prices" v-else :key="c.country_id">
                                    <td class="ps-4">
                                        <span class="d-block fw-semibold">{{ c.name }}</span>
                                        <span class="text-muted fs-11">{{ c.code }} · {{ c.currency || '—' }}</span>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm price-input">
                                            <input v-model="drafts[c.country_id].monthly" type="text" inputmode="decimal" class="form-control" dir="ltr" :disabled="!canUpdate || !c.currency">
                                            <span class="input-group-text">{{ c.currency }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm price-input">
                                            <input v-model="drafts[c.country_id].yearly" type="text" inputmode="decimal" class="form-control" dir="ltr" :disabled="!canUpdate || !c.currency">
                                            <span class="input-group-text">{{ c.currency }}</span>
                                        </div>
                                    </td>
                                    <td class="fs-12">
                                        <span v-if="saving(c) != null" class="badge" :class="saving(c) > 0 ? 'bg-success-transparent' : 'bg-danger-transparent'">{{ saving(c) }}%</span>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button v-if="canUpdate" type="button" class="btn btn-sm btn-primary btn-wave" :disabled="!dirty(c) || savingId === c.country_id" @click="savePrice(c)">
                                            <span v-if="savingId === c.country_id" class="spinner-border spinner-border-sm"></span><template v-else>{{ t('save') }}</template>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <PagerFooter v-if="!priceLoading && prices.length" :pagination="pricePage" :page="pricePageNo" @prev="pricePageNo -= 1" @next="pricePageNo += 1" />
            </template>

            <!-- Subscribers. -->
            <template v-else-if="tab === 'subscribers'">
                <div class="card-body border-bottom py-3 d-flex align-items-center flex-wrap gap-2">
                    <FlStatusFilter v-model="subStatus" :options="subStatusOptions" />
                    <Select v-model="subPlan" :options="subPlanOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                    <div class="input-group input-group-sm catalog-toolbar-search ms-auto">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="subSearch" type="search" class="form-control" :placeholder="t('freelance.freelancers.search')">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">{{ isTeam ? t('freelance.plans.team') : t('freelance.freelancers.freelancer') }}</th>
                                    <th>{{ t('freelance.plans.plan') }}</th>
                                    <th>{{ t('wallet.common.status') }}</th>
                                    <th>{{ t('freelance.plans.price') }}</th>
                                    <th class="pe-4">{{ t('freelance.plans.renews') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <TableSkeleton v-if="subLoading" :rows="6" :layout="['two-lines', 'badge', 'badge', 'text', 'text']" />
                                <tr v-else-if="!subs.length"><td colspan="5" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                                <tr v-for="s in subs" v-else :key="s.uuid">
                                    <td v-if="isTeam" class="ps-4">
                                        <span class="d-block fw-semibold">{{ s.team?.name || '—' }}</span>
                                        <span class="text-muted fs-11">{{ t('freelance.plans.paid_by') }}: {{ s.freelancer?.name || '—' }} <span dir="ltr">{{ s.freelancer?.phone }}</span></span>
                                    </td>
                                    <td v-else class="ps-4">
                                        <span class="d-block fw-semibold">{{ s.freelancer.name || '—' }}</span>
                                        <span class="text-muted fs-11" dir="ltr">{{ s.freelancer.phone }}</span>
                                    </td>
                                    <td>
                                        <span class="d-block">{{ s.plan.name }}</span>
                                        <span class="text-muted fs-11">{{ t(`freelance.plans.periods.${s.period}`) }}</span>
                                    </td>
                                    <td>
                                        <span class="badge" :class="subBadge(s.status)">{{ t(`freelance.plans.status.${s.status}`) }}</span>
                                        <span v-if="!s.auto_renew && s.status !== 'expired'" class="badge bg-secondary-transparent ms-1">{{ t('freelance.plans.no_renew') }}</span>
                                    </td>
                                    <td class="fs-12" dir="ltr">{{ fmt(s.price_minor, s.decimals) }} {{ s.currency }}</td>
                                    <td class="fs-12 pe-4">{{ s.status === 'expired' ? formatCatalogDate(s.ended_at, locale) : formatCatalogDate(s.status === 'trialing' ? (s.trial_ends_at ?? s.period_end) : s.period_end, locale) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <PagerFooter v-if="!subLoading && subs.length" :pagination="subPage" :page="subPageNo" @prev="subPageNo -= 1" @next="subPageNo += 1" />
            </template>

            <!-- Extra offers: the packs and their price in every country. -->
            <template v-else-if="tab === 'packs'">
                <div class="card-body border-bottom">
                    <div class="alert alert-light border fs-13 mb-3">{{ t('freelance.plans.packs_hint', { days: validDays }) }}</div>
                    <div v-if="packLoading" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm"></span></div>
                    <div v-else class="row g-3">
                        <div v-for="p in packs" :key="p.id" class="col-xl-4 col-md-6">
                            <div class="border rounded-3 p-3 h-100 d-flex flex-column">
                                <div class="d-flex align-items-start gap-2 mb-2">
                                    <div class="flex-grow-1">
                                        <span class="d-block fw-semibold fs-16">{{ packName(p) }}</span>
                                        <code class="fs-11 text-muted">{{ p.slug }}</code>
                                    </div>
                                    <span class="badge" :class="p.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ p.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                </div>
                                <ul class="list-unstyled fs-13 mb-3 flex-grow-1">
                                    <li class="mb-1"><i class="ri-send-plane-line me-2 text-primary"></i>{{ t('freelance.plans.pack_offers', { n: p.offers }) }}</li>
                                    <li class="mb-1"><i class="ri-shopping-bag-3-line me-2 text-primary"></i>{{ t('freelance.plans.pack_sold', { n: p.sold_30d }) }}</li>
                                </ul>
                                <div v-if="canUpdate" class="text-end">
                                    <button type="button" class="btn btn-sm btn-light btn-wave" @click="openPack(p)"><i class="ri-edit-line me-1"></i>{{ t('freelance.plans.edit') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="packRevenueText" class="text-muted fs-12 mt-3">{{ t('freelance.plans.pack_revenue', { amount: packRevenueText }) }}</div>
                </div>
                <div class="card-body border-bottom py-3 d-flex align-items-center flex-wrap gap-2">
                    <Select v-model="packPriceId" :options="packOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="packSearch" type="search" class="form-control" :placeholder="t('freelance.plans.search_country')">
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input id="packs-missing" v-model="packMissing" class="form-check-input" type="checkbox">
                        <label class="form-check-label fs-13" for="packs-missing">{{ t('freelance.plans.only_missing') }}</label>
                    </div>
                    <button v-if="canUpdate" type="button" class="btn btn-outline-primary btn-sm btn-wave ms-auto" :disabled="filling" @click="fillMissing">
                        <span v-if="filling" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-magic-line me-1"></i>{{ t('freelance.plans.fill') }}
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">{{ t('freelance.freelancers.country') }}</th>
                                    <th>{{ t('freelance.plans.price') }}</th>
                                    <th class="text-end pe-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <TableSkeleton v-if="packPriceLoading" :rows="6" :layout="['two-lines', 'text', 'actions']" />
                                <tr v-else-if="!packPrices.length"><td colspan="3" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                                <tr v-for="c in packPrices" v-else :key="c.country_id">
                                    <td class="ps-4">
                                        <span class="d-block fw-semibold">{{ c.name }}</span>
                                        <span class="text-muted fs-11">{{ c.code }} · {{ c.currency || '—' }}</span>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm price-input">
                                            <input v-model="packDrafts[c.country_id]" type="text" inputmode="decimal" class="form-control" dir="ltr" :disabled="!canUpdate || !c.currency">
                                            <span class="input-group-text">{{ c.currency }}</span>
                                        </div>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button v-if="canUpdate" type="button" class="btn btn-sm btn-primary btn-wave" :disabled="!packDirty(c) || packSavingId === c.country_id" @click="savePackPrice(c)">
                                            <span v-if="packSavingId === c.country_id" class="spinner-border spinner-border-sm"></span><template v-else>{{ t('save') }}</template>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <PagerFooter v-if="!packPriceLoading && packPrices.length" :pagination="packPricePage" :page="packPricePageNo" @prev="packPricePageNo -= 1" @next="packPricePageNo += 1" />
            </template>

            <!-- Who bought extra offers. -->
            <template v-else>
                <div class="card-body border-bottom py-3 d-flex align-items-center flex-wrap gap-2">
                    <Select v-model="buyPack" :options="buyPackOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                    <div class="input-group input-group-sm catalog-toolbar-search ms-auto">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="buySearch" type="search" class="form-control" :placeholder="t('freelance.freelancers.search')">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">{{ t('freelance.freelancers.freelancer') }}</th>
                                    <th>{{ t('freelance.plans.pack') }}</th>
                                    <th>{{ t('freelance.plans.pack_left') }}</th>
                                    <th>{{ t('freelance.plans.price') }}</th>
                                    <th class="pe-4">{{ t('freelance.plans.pack_ends') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <TableSkeleton v-if="buyLoading" :rows="6" :layout="['two-lines', 'text', 'text', 'text', 'text']" />
                                <tr v-else-if="!buys.length"><td colspan="5" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                                <tr v-for="b in buys" v-else :key="b.uuid">
                                    <td class="ps-4">
                                        <span class="d-block fw-semibold">{{ b.freelancer.name || '—' }}</span>
                                        <span class="text-muted fs-11" dir="ltr">{{ b.freelancer.phone }}</span>
                                    </td>
                                    <td>
                                        <span class="d-block">{{ b.pack || '—' }}</span>
                                        <span class="text-muted fs-11">{{ formatCatalogDate(b.created_at, locale) }}</span>
                                    </td>
                                    <td><span class="badge" :class="b.remaining ? 'bg-info-transparent' : 'bg-secondary-transparent'">{{ b.remaining }} / {{ b.quantity }}</span></td>
                                    <td class="fs-12" dir="ltr">{{ fmt(b.price_minor, b.decimals) }} {{ b.currency }}</td>
                                    <td class="fs-12 pe-4">{{ b.expires_at ? formatCatalogDate(b.expires_at, locale) : t('freelance.plans.pack_never') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <PagerFooter v-if="!buyLoading && buys.length" :pagination="buyPage" :page="buyPageNo" @prev="buyPageNo -= 1" @next="buyPageNo += 1" />
            </template>
        </div>

        <WalletModal :show="!!editing" :title="t('freelance.plans.edit_title', { name: editing ? planName(editing) : '' })" size="lg" @close="editing = null">
            <form v-if="editing" id="freelance-plan-form" novalidate @submit.prevent="savePlan">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.plans.name_ar') }} <span class="text-danger">*</span></label>
                        <input v-model="form.name.ar" type="text" class="form-control" maxlength="60">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.plans.name_en') }} <span class="text-danger">*</span></label>
                        <input v-model="form.name.en" type="text" class="form-control" dir="ltr" maxlength="60">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.plans.description_ar') }}</label>
                        <textarea v-model="form.description.ar" class="form-control" rows="2" maxlength="300"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.plans.description_en') }}</label>
                        <textarea v-model="form.description.en" class="form-control" dir="ltr" rows="2" maxlength="300"></textarea>
                    </div>
                    <template v-if="editing.audience === 'team'">
                        <div class="col-md-6">
                            <label class="form-label">{{ t('freelance.plans.team_members_max') }}</label>
                            <input v-model="form.team_members_max" type="number" min="1" max="1000" class="form-control" :placeholder="t('freelance.plans.no_cap')">
                            <div class="form-text">{{ t('freelance.plans.team_members_hint') }}</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ t('freelance.plans.team_projects_max') }}</label>
                            <input v-model="form.team_projects_max" type="number" min="1" max="100000" class="form-control" :placeholder="t('freelance.plans.no_cap')">
                            <div class="form-text">{{ t('freelance.plans.team_projects_hint') }}</div>
                        </div>
                    </template>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input id="plan-unlimited" v-model="form.offers_unlimited" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="plan-unlimited">{{ t('freelance.plans.offers_unlimited') }}</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('freelance.plans.offers_monthly') }}</label>
                        <input v-model.number="form.offers_monthly" type="number" min="0" class="form-control" :disabled="form.offers_unlimited">
                        <div class="form-text">{{ editing.audience === 'team' ? t('freelance.plans.team_offers_hint') : t('freelance.plans.offers_monthly_hint') }}</div>
                    </div>
                    <div v-if="editing.audience !== 'team'" class="col-md-4">
                        <label class="form-label">{{ t('freelance.plans.commission') }}</label>
                        <div class="input-group">
                            <input v-model="form.commission" type="text" inputmode="decimal" class="form-control" dir="ltr">
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">{{ t('freelance.plans.commission_hint') }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('freelance.plans.trial_label') }}</label>
                        <input v-model.number="form.trial_days" type="number" min="0" max="90" class="form-control">
                    </div>
                    <div v-if="editing.audience !== 'team'" class="col-12">
                        <div class="form-check form-switch">
                            <input id="plan-badge" v-model="form.badge" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="plan-badge">{{ t('freelance.plans.badge') }}</label>
                        </div>
                        <div class="form-text">{{ t('freelance.plans.badge_hint') }}</div>
                    </div>
                    <div v-if="editing.audience !== 'team'" class="col-md-4">
                        <label class="form-label">{{ t('freelance.plans.boost') }}</label>
                        <select v-model.number="form.boost" class="form-select">
                            <option v-for="n in [0, 1, 2, 3]" :key="n" :value="n">{{ n === 0 ? t('freelance.plans.boost_none') : n }}</option>
                        </select>
                        <div class="form-text">{{ t('freelance.plans.boost_hint') }}</div>
                    </div>
                    <div v-if="editing.audience !== 'team'" class="col-md-4">
                        <label class="form-label">{{ t('freelance.plans.analytics') }}</label>
                        <select v-model.number="form.analytics" class="form-select">
                            <option :value="0">{{ t('freelance.plans.analytics_0') }}</option>
                            <option :value="1">{{ t('freelance.plans.analytics_1') }}</option>
                            <option :value="2">{{ t('freelance.plans.analytics_2') }}</option>
                        </select>
                        <div class="form-text">{{ t('freelance.plans.analytics_hint') }}</div>
                    </div>
                    <div v-if="editing.audience !== 'team'" class="col-md-4">
                        <label class="form-label">{{ t('freelance.plans.services_max') }}</label>
                        <input v-model="form.services_max" type="number" min="1" max="1000" class="form-control" :placeholder="t('freelance.plans.services_max_policy')">
                        <div class="form-text">{{ t('freelance.plans.services_max_hint') }}</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input id="plan-status" v-model="form.status" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="plan-status">{{ t('freelance.plans.offered') }}</label>
                        </div>
                        <div class="form-text">{{ t('freelance.plans.offered_hint') }}</div>
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="editing = null">{{ t('cancel') }}</button>
                <button type="submit" form="freelance-plan-form" class="btn btn-primary" :disabled="planSaving">
                    <span v-if="planSaving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>

        <WalletModal :show="!!editingPack" :title="t('freelance.plans.edit_title', { name: editingPack ? packName(editingPack) : '' })" size="md" @close="editingPack = null">
            <form v-if="editingPack" id="freelance-pack-form" novalidate @submit.prevent="savePack">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.plans.name_ar') }} <span class="text-danger">*</span></label>
                        <input v-model="packForm.name.ar" type="text" class="form-control" maxlength="60">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.plans.name_en') }} <span class="text-danger">*</span></label>
                        <input v-model="packForm.name.en" type="text" class="form-control" dir="ltr" maxlength="60">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.plans.pack_offers_label') }}</label>
                        <input v-model.number="packForm.offers" type="number" min="1" class="form-control">
                        <div class="form-text">{{ t('freelance.plans.pack_offers_hint') }}</div>
                    </div>
                    <div class="col-md-6 d-flex align-items-center">
                        <div class="form-check form-switch">
                            <input id="pack-status" v-model="packForm.status" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="pack-status">{{ t('freelance.plans.offered') }}</label>
                        </div>
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="editingPack = null">{{ t('cancel') }}</button>
                <button type="submit" form="freelance-pack-form" class="btn btn-primary" :disabled="packSaving">
                    <span v-if="packSaving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, defineComponent, h, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import FlStatTile from '../../../../../../../components/freelance/FlStatTile.vue';
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { formatCatalogDate } from '../../../../../../../utils/catalog';

// The plans desk (FL-L01, FS-211): what each plan gives, its monthly and yearly price in every country, and who is subscribed. Two audiences
// share it: the freelancers' plans, and the teams' plans (the room they open for a whole team, paid by one Owner). Prices are whole minor units
// of the country's currency; the screen shows them with the currency's own decimals.
const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const base = '/api/admin/v1/freelance-plans';
const canUpdate = computed(() => can('freelance-plans.update'));

const PagerFooter = defineComponent({
    props: { pagination: { type: Object, default: () => ({}) }, page: { type: Number, default: 1 } },
    emits: ['prev', 'next'],
    setup(props, { emit }) {
        return () => h('div', { class: 'card-footer border-top-0 d-flex align-items-center flex-wrap gap-3' }, [
            h('div', { class: 'text-muted fs-13' }, t('countries.showing_entries', { from: props.pagination.from ?? 0, to: props.pagination.to ?? 0, total: props.pagination.total ?? 0 })),
            (props.pagination.last_page ?? 1) > 1 && h('nav', { class: 'pagination-style-4 ms-md-auto' }, h('ul', { class: 'pagination mb-0' }, [
                h('li', { class: ['page-item', { disabled: props.page <= 1 }] }, h('button', { type: 'button', class: 'page-link', onClick: () => emit('prev') }, t('countries.previous'))),
                h('li', { class: 'page-item active' }, h('span', { class: 'page-link' }, props.page)),
                h('li', { class: ['page-item', { disabled: props.page >= props.pagination.last_page }] }, h('button', { type: 'button', class: 'page-link text-primary', onClick: () => emit('next') }, t('countries.next'))),
            ])),
        ]);
    },
});

const tab = ref('plans');
// Whose plans the screen shows: `freelancer` or `team`. The extra-offer packs are a freelancer's only.
const audience = ref('freelancer');
const isTeam = computed(() => audience.value === 'team');
const audienceOptions = computed(() => [
    { value: 'freelancer', label: t('freelance.plans.audience.freelancers'), icon: 'ri-user-star-line' },
    { value: 'team', label: t('freelance.plans.audience.teams'), icon: 'ri-team-line' },
]);
const tabOptions = computed(() => [
    { value: 'plans', label: t('freelance.plans.tabs.plans'), tone: 'all' },
    { value: 'prices', label: t('freelance.plans.tabs.prices'), tone: 'all' },
    { value: 'subscribers', label: t('freelance.plans.tabs.subscribers'), tone: 'all' },
    ...(isTeam.value ? [] : [
        { value: 'packs', label: t('freelance.plans.tabs.packs'), tone: 'all' },
        { value: 'purchases', label: t('freelance.plans.tabs.purchases'), tone: 'all' },
    ]),
]);

// ---- money: minor units <-> what the staff type, with the currency's decimals
const fmt = (minor, decimals = 2) => (minor == null ? '' : (Number(minor) / 10 ** decimals).toFixed(decimals));
const toMinor = (text, decimals = 2) => {
    const n = Number(String(text ?? '').trim().replace(',', '.'));
    return Number.isFinite(n) && n > 0 ? Math.round(n * 10 ** decimals) : Number.NaN;
};

// ---- the plans
const loading = ref(false);
const overview = reactive({ plans: [], revenue_30d: [], subscribers: 0, team_subscribers: 0 });
const subCounts = ref({});
const shownPlans = computed(() => overview.plans.filter((p) => (p.audience ?? 'freelancer') === audience.value));
const paidPlans = computed(() => shownPlans.value.filter((p) => !p.free));
const planName = (p) => p.name?.[locale.value] || p.name?.en || p.slug;
const descriptionOf = (p) => p.description?.[locale.value] || p.description?.en || '';
const offersText = (p) => {
    if (p.free) return t('freelance.plans.offers_free');
    return p.offers_unlimited ? t('freelance.plans.offers_unlimited_text') : t('freelance.plans.offers_n', { n: p.offers_monthly ?? 0 });
};
const teamOffersText = (p) => {
    if (p.offers_unlimited) return t('freelance.plans.offers_unlimited_text');
    return p.offers_monthly == null ? t('freelance.plans.team_offers_policy') : t('freelance.plans.offers_n', { n: p.offers_monthly });
};
const commissionText = (p) => (p.commission_bp == null ? t('freelance.plans.commission_policy') : t('freelance.plans.commission_n', { n: p.commission_bp / 100 }));
const perksText = (p) => [
    p.badge ? t('freelance.plans.perk_badge') : null,
    p.boost > 0 ? t('freelance.plans.perk_boost', { n: p.boost }) : null,
    p.analytics > 0 ? t(`freelance.plans.perk_analytics_${p.analytics}`) : null,
    p.services_max ? t('freelance.plans.perk_services', { n: p.services_max }) : null,
].filter(Boolean).join(' · ') || t('freelance.plans.perk_none');
const revenueText = computed(() => overview.revenue_30d.map((r) => `${fmt(r.total_minor, r.decimals)} ${r.currency}`).join(' · '));

async function loadOverview() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(base);
        Object.assign(overview, data.data ?? {});
        pricePlanId.value ??= paidPlans.value[0]?.id ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

const editing = ref(null);
const planSaving = ref(false);
const form = reactive({
    name: { ar: '', en: '' }, description: { ar: '', en: '' }, offers_monthly: 0, offers_unlimited: false, commission: '', trial_days: 0, badge: false, boost: 0, analytics: 0, services_max: '',
    team_members_max: '', team_projects_max: '', status: true,
});

function openEdit(p) {
    form.name = { ar: p.name?.ar ?? '', en: p.name?.en ?? '' };
    form.description = { ar: p.description?.ar ?? '', en: p.description?.en ?? '' };
    form.offers_monthly = p.offers_monthly ?? 0;
    form.offers_unlimited = !!p.offers_unlimited;
    form.commission = p.commission_bp == null ? '' : String(p.commission_bp / 100);
    form.trial_days = p.trial_days ?? 0;
    form.badge = !!p.badge;
    form.boost = p.boost ?? 0;
    form.analytics = p.analytics ?? 0;
    form.services_max = p.services_max == null ? '' : String(p.services_max);
    form.team_members_max = p.team_members_max == null ? '' : String(p.team_members_max);
    form.team_projects_max = p.team_projects_max == null ? '' : String(p.team_projects_max);
    form.status = !!p.status;
    editing.value = p;
}

const numberOrNull = (v) => (String(v ?? '').trim() === '' ? null : Number(v));

async function savePlan() {
    const team = editing.value.audience === 'team';
    const percent = String(form.commission ?? '').trim().replace(',', '.');
    if (!team && percent !== '' && !(Number(percent) >= 0 && Number(percent) <= 100)) {
        showError(t('freelance.plans.commission_invalid'));
        return;
    }
    planSaving.value = true;
    try {
        // A team plan has the room it opens and no commission, badge, boost, analytics or services: those are a freelancer's.
        const body = {
            name: form.name,
            description: form.description,
            offers_unlimited: form.offers_unlimited,
            offers_monthly: form.offers_unlimited ? null : (form.offers_monthly ?? 0),
            trial_days: form.trial_days ?? 0,
            status: form.status,
        };
        Object.assign(body, team
            ? { team_members_max: numberOrNull(form.team_members_max), team_projects_max: numberOrNull(form.team_projects_max) }
            : {
                commission_bp: percent === '' ? null : Math.round(Number(percent) * 100),
                badge: form.badge,
                boost: form.boost ?? 0,
                analytics: form.analytics ?? 0,
                services_max: numberOrNull(form.services_max),
            });
        const response = await adminAxios.put(`${base}/${editing.value.id}`, body);
        showSuccess(extractApiMessage(response, t('toast.success')));
        editing.value = null;
        loadOverview();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        planSaving.value = false;
    }
}

// ---- prices
const pricePlanId = ref(null);
const priceSearch = ref('');
const onlyMissing = ref(false);
const priceLoading = ref(false);
const prices = ref([]);
const pricePage = ref({});
const pricePageNo = ref(1);
const drafts = reactive({});
const savingId = ref(null);
const filling = ref(false);
const paidPlanOptions = computed(() => paidPlans.value.map((p) => ({ value: p.id, label: planName(p) })));

const decimalsOf = (c) => c.decimals ?? 2;
const dirty = (c) => {
    const d = drafts[c.country_id];
    if (!d) return false;
    const m = toMinor(d.monthly, decimalsOf(c));
    const y = toMinor(d.yearly, decimalsOf(c));
    if (Number.isNaN(m) || Number.isNaN(y)) return false;
    return m !== c.monthly?.price_minor || y !== c.yearly?.price_minor;
};
const saving = (c) => {
    const d = drafts[c.country_id];
    if (!d) return null;
    const m = toMinor(d.monthly, decimalsOf(c));
    const y = toMinor(d.yearly, decimalsOf(c));
    if (Number.isNaN(m) || Number.isNaN(y)) return null;
    return Math.round((1 - y / (m * 12)) * 100);
};

let priceTimer = null;
watch(priceSearch, () => { clearTimeout(priceTimer); priceTimer = setTimeout(() => (pricePageNo.value === 1 ? loadPrices() : (pricePageNo.value = 1)), 300); });
watch([pricePlanId, onlyMissing], () => (pricePageNo.value === 1 ? loadPrices() : (pricePageNo.value = 1)));
watch(pricePageNo, loadPrices);

async function loadPrices() {
    if (!pricePlanId.value) return;
    priceLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/${pricePlanId.value}/prices`, {
            params: { page: pricePageNo.value, search: priceSearch.value || undefined, missing: onlyMissing.value ? 1 : undefined },
        });
        prices.value = data.data ?? [];
        pricePage.value = data.pagination ?? {};
        prices.value.forEach((c) => {
            drafts[c.country_id] = { monthly: fmt(c.monthly?.price_minor, decimalsOf(c)), yearly: fmt(c.yearly?.price_minor, decimalsOf(c)) };
        });
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        priceLoading.value = false;
    }
}

async function savePrice(c) {
    const d = drafts[c.country_id];
    const monthly = toMinor(d.monthly, decimalsOf(c));
    const yearly = toMinor(d.yearly, decimalsOf(c));
    if (Number.isNaN(monthly) || Number.isNaN(yearly)) {
        showError(t('freelance.plans.price_invalid'));
        return;
    }
    if (yearly > monthly * 12) {
        showError(t('freelance.plans.yearly_too_high'));
        return;
    }
    savingId.value = c.country_id;
    try {
        const response = await adminAxios.put(`${base}/${pricePlanId.value}/prices/${c.country_id}`, { monthly_minor: monthly, yearly_minor: yearly });
        showSuccess(extractApiMessage(response, t('toast.success')));
        c.monthly = { price_minor: monthly, status: true };
        c.yearly = { price_minor: yearly, status: true };
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        savingId.value = null;
    }
}

async function fillMissing() {
    filling.value = true;
    try {
        const response = await adminAxios.post(`${base}/fill-prices`);
        const r = response.data.data ?? {};
        showSuccess(t('freelance.plans.filled', { plans: (r.plans ?? 0) + (r.packs ?? 0), prices: (r.prices ?? 0) + (r.pack_prices ?? 0) }));
        loadOverview();
        loadPrices();
        if (tab.value === 'packs') loadPacks().then(loadPackPrices);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        filling.value = false;
    }
}

// ---- subscribers
const subs = ref([]);
const subPage = ref({});
const subPageNo = ref(1);
const subLoading = ref(false);
const subStatus = ref('all');
const subPlan = ref('all');
const subSearch = ref('');
const subPlanOptions = computed(() => [{ value: 'all', label: t('freelance.plans.all_plans') }, ...paidPlans.value.map((p) => ({ value: p.slug, label: planName(p) }))]);
const subTotal = computed(() => Object.values(subCounts.value).reduce((a, b) => a + b, 0));
const subStatusOptions = computed(() => [
    { value: 'all', label: t('users.filter_all'), count: subTotal.value, tone: 'all' },
    ...['trialing', 'active', 'past_due', 'expired'].map((s) => ({
        value: s, label: t(`freelance.plans.status.${s}`), count: subCounts.value[s] ?? 0,
        tone: { trialing: 'pending', active: 'active', past_due: 'deleted', expired: 'inactive' }[s],
    })),
]);
const subBadge = (s) => ({ trialing: 'bg-info-transparent', active: 'bg-success-transparent', past_due: 'bg-warning-transparent', expired: 'bg-secondary-transparent' }[s] ?? 'bg-light text-default');

let subTimer = null;
watch(subSearch, () => { clearTimeout(subTimer); subTimer = setTimeout(() => (subPageNo.value === 1 ? loadSubs() : (subPageNo.value = 1)), 300); });
watch([subStatus, subPlan, audience], () => (subPageNo.value === 1 ? loadSubs() : (subPageNo.value = 1)));
watch(subPageNo, loadSubs);
// Switching between the freelancers' plans and the teams': start that side from its first plan and an unfiltered list.
watch(audience, () => {
    if (isTeam.value && ['packs', 'purchases'].includes(tab.value)) tab.value = 'plans';
    pricePlanId.value = paidPlans.value[0]?.id ?? null;
    prices.value = [];
    subPlan.value = 'all';
    subStatus.value = 'all';
    subSearch.value = '';
});
watch(tab, (v) => {
    if (v === 'prices' && !prices.value.length) loadPrices();
    if (v === 'subscribers') loadSubs();
    if (v === 'packs') {
        loadPacks().then(loadPackPrices);
    }
    if (v === 'purchases') {
        if (!packs.value.length) loadPacks();
        loadBuys();
    }
});

async function loadSubs() {
    subLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/subscriptions`, {
            params: {
                page: subPageNo.value, search: subSearch.value || undefined, audience: isTeam.value ? 'team' : undefined,
                status: subStatus.value === 'all' ? undefined : subStatus.value, plan: subPlan.value === 'all' ? undefined : subPlan.value,
            },
        });
        subs.value = data.data ?? [];
        subPage.value = data.pagination ?? {};
        subCounts.value = data.pagination?.counts ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        subLoading.value = false;
    }
}

// ---- extra offers: the packs, their prices per country, and who bought
const packs = ref([]);
const packRevenue = ref([]);
const validDays = ref(0);
const packLoading = ref(false);
const editingPack = ref(null);
const packSaving = ref(false);
const packForm = reactive({ name: { ar: '', en: '' }, offers: 10, status: true });
const packName = (p) => p.name?.[locale.value] || p.name?.en || p.slug;
const packOptions = computed(() => packs.value.map((p) => ({ value: p.id, label: packName(p) })));
const packRevenueText = computed(() => packRevenue.value.map((r) => `${fmt(r.total_minor, r.decimals)} ${r.currency}`).join(' · '));

async function loadPacks() {
    packLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/packs`);
        packs.value = data.data?.packs ?? [];
        packRevenue.value = data.data?.revenue_30d ?? [];
        validDays.value = data.data?.valid_days ?? 0;
        packPriceId.value ??= packs.value[0]?.id ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        packLoading.value = false;
    }
}

function openPack(p) {
    packForm.name = { ar: p.name?.ar ?? '', en: p.name?.en ?? '' };
    packForm.offers = p.offers;
    packForm.status = !!p.status;
    editingPack.value = p;
}

async function savePack() {
    packSaving.value = true;
    try {
        const response = await adminAxios.put(`${base}/packs/${editingPack.value.id}`, { name: packForm.name, offers: packForm.offers, status: packForm.status });
        showSuccess(extractApiMessage(response, t('toast.success')));
        editingPack.value = null;
        loadPacks();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        packSaving.value = false;
    }
}

const packPriceId = ref(null);
const packSearch = ref('');
const packMissing = ref(false);
const packPriceLoading = ref(false);
const packPrices = ref([]);
const packPricePage = ref({});
const packPricePageNo = ref(1);
const packDrafts = reactive({});
const packSavingId = ref(null);
const packDirty = (c) => {
    const m = toMinor(packDrafts[c.country_id], c.decimals ?? 2);
    return !Number.isNaN(m) && m !== c.price?.price_minor;
};

let packTimer = null;
watch(packSearch, () => { clearTimeout(packTimer); packTimer = setTimeout(() => (packPricePageNo.value === 1 ? loadPackPrices() : (packPricePageNo.value = 1)), 300); });
watch([packPriceId, packMissing], () => (packPricePageNo.value === 1 ? loadPackPrices() : (packPricePageNo.value = 1)));
watch(packPricePageNo, loadPackPrices);

async function loadPackPrices() {
    if (!packPriceId.value) return;
    packPriceLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/packs/${packPriceId.value}/prices`, {
            params: { page: packPricePageNo.value, search: packSearch.value || undefined, missing: packMissing.value ? 1 : undefined },
        });
        packPrices.value = data.data ?? [];
        packPricePage.value = data.pagination ?? {};
        packPrices.value.forEach((c) => { packDrafts[c.country_id] = fmt(c.price?.price_minor, c.decimals ?? 2); });
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        packPriceLoading.value = false;
    }
}

async function savePackPrice(c) {
    const minor = toMinor(packDrafts[c.country_id], c.decimals ?? 2);
    if (Number.isNaN(minor)) {
        showError(t('freelance.plans.price_invalid_one'));
        return;
    }
    packSavingId.value = c.country_id;
    try {
        const response = await adminAxios.put(`${base}/packs/${packPriceId.value}/prices/${c.country_id}`, { price_minor: minor });
        showSuccess(extractApiMessage(response, t('toast.success')));
        c.price = { price_minor: minor, status: true };
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        packSavingId.value = null;
    }
}

const buys = ref([]);
const buyPage = ref({});
const buyPageNo = ref(1);
const buyLoading = ref(false);
const buyPack = ref('all');
const buySearch = ref('');
const buyPackOptions = computed(() => [{ value: 'all', label: t('freelance.plans.all_packs') }, ...packs.value.map((p) => ({ value: p.slug, label: packName(p) }))]);

let buyTimer = null;
watch(buySearch, () => { clearTimeout(buyTimer); buyTimer = setTimeout(() => (buyPageNo.value === 1 ? loadBuys() : (buyPageNo.value = 1)), 300); });
watch(buyPack, () => (buyPageNo.value === 1 ? loadBuys() : (buyPageNo.value = 1)));
watch(buyPageNo, loadBuys);

async function loadBuys() {
    buyLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/pack-purchases`, { params: { page: buyPageNo.value, search: buySearch.value || undefined, pack: buyPack.value === 'all' ? undefined : buyPack.value } });
        buys.value = data.data ?? [];
        buyPage.value = data.pagination ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        buyLoading.value = false;
    }
}

onMounted(async () => {
    await loadOverview();
    loadSubs();
});
</script>

<style scoped>
.price-input {
    max-width: 190px;
}
</style>
