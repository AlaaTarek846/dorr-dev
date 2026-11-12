/**
 * The AI Marketing Director's pages, the same for the user and the provider SPA. `section` is the SPA's thin wrapper
 * (it provides the axios instance and the API base); the pages themselves are shared. Route names: `{portal}.marketing`,
 * `{portal}.marketing.packages`, `.project`, `.discovery`, `.research`, `.plan`, `.finance`, `.team`.
 */
export default function marketingRoutes(portal, section, middleware) {
    const name = (page) => `${portal}.marketing.${page}`;

    return {
        path: 'marketing',
        component: section,
        meta: { middleware },
        children: [
            { path: '', name: `${portal}.marketing`, component: () => import('./MarketingHome.vue') },
            { path: 'packages', name: name('packages'), component: () => import('./MarketingPackages.vue') },
            { path: 'projects/:projectId', name: name('project'), component: () => import('./MarketingProject.vue') },
            { path: 'projects/:projectId/discovery', name: name('discovery'), component: () => import('./MarketingDiscovery.vue') },
            { path: 'projects/:projectId/research/:runId', name: name('research'), component: () => import('./MarketingResearch.vue') },
            { path: 'projects/:projectId/plans/:planId', name: name('plan'), component: () => import('./MarketingPlan.vue') },
            { path: 'projects/:projectId/plans/:planId/finance', name: name('finance'), component: () => import('./MarketingFinance.vue') },
            { path: 'projects/:projectId/team', name: name('team'), component: () => import('./MarketingTeam.vue') },
            // Phase B — execution (calendar, ad studio, budgets, results, report).
            { path: 'projects/:projectId/calendar', name: name('calendar'), component: () => import('./MarketingCalendar.vue') },
            { path: 'projects/:projectId/studio', name: name('studio'), component: () => import('./MarketingStudio.vue') },
            { path: 'projects/:projectId/content/:itemId', name: name('content'), component: () => import('./MarketingContent.vue') },
            { path: 'projects/:projectId/budgets', name: name('budgets'), component: () => import('./MarketingBudgets.vue') },
            { path: 'projects/:projectId/results', name: name('results'), component: () => import('./MarketingResults.vue') },
            { path: 'projects/:projectId/report', name: name('report'), component: () => import('./MarketingReport.vue') },
            // Phase C — ad platforms and campaigns.
            { path: 'projects/:projectId/platforms', name: name('platforms'), component: () => import('./MarketingPlatforms.vue') },
            { path: 'projects/:projectId/campaigns', name: name('campaigns'), component: () => import('./MarketingCampaigns.vue') },
            { path: 'projects/:projectId/campaigns/:campaignId', name: name('campaign'), component: () => import('./MarketingCampaign.vue') },
            // Phase D — optimisation (suggestions, alert rules, A/B tests).
            { path: 'projects/:projectId/optimise', name: name('optimise'), component: () => import('./MarketingOptimise.vue') },
        ],
    };
}
