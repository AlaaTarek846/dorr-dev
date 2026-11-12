import { useCatalog } from './useCatalog';
import {
    useAiGatewaysStore,
    useAiIntentsStore,
    useAiLearnedIntentsStore,
    useAiRoutingPoliciesStore,
    useAiRoutingRulesStore,
} from '../stores/aiRoutingScreens';

/**
 * The AI routing screens' lists (intents, learned phrases, routing policies, gateways, routing rules) - the same
 * catalog list as useCountries: search, All / Active / Inactive / Trash filters with counts, bulk delete, status
 * toggle, restore / force delete. Full counts (not lazy): the Trash filter only shows when its count is known.
 *
 * Each returns useCatalog()'s API; the rows are `items`.
 */
function routingCatalog(apiUri, countsStore, confirmDeleteKey, searchDefaults, getExtraListParams = null) {
    return useCatalog({
        apiUri,
        countsStore,
        lazyCounts: false,
        confirmDeleteKey,
        searchDefaults,
        dataKey: 'items',
        getExtraListParams,
    });
}

/** Intents: the key (technical) + the name / description in every language. */
export function useAiIntents() {
    return routingCatalog('/api/admin/v1/ai-intents', useAiIntentsStore(), 'ai_intents.confirm_delete', {
        columns: ['key'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
    });
}

/**
 * Learned phrases: the phrase (the server normalizes the typed text the same way). `getFilters()` adds the
 * screen's own filters (state, intent, mode, source) to the list and its counts.
 */
export function useAiLearnedIntents({ getFilters } = {}) {
    return routingCatalog('/api/admin/v1/ai-learned-intents', useAiLearnedIntentsStore(), 'ai_learned_intents.confirm_delete', {
        columns: ['phrase'],
        searchInTranslations: false,
        filterTranslationByLocale: false,
    }, getFilters ?? null);
}

/** Routing policies: the technical codes + the name in every language. */
export function useAiRoutingPolicies() {
    return routingCatalog('/api/admin/v1/ai-routing-policies', useAiRoutingPoliciesStore(), 'ai_routing_policies.confirm_delete', {
        columns: ['scope_type', 'country_code', 'service_key'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
    });
}

/** Gateways: the environment + the name in every language. */
export function useAiGateways() {
    return routingCatalog('/api/admin/v1/ai-gateways', useAiGatewaysStore(), 'ai_gateways.confirm_delete', {
        columns: ['environment'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
    });
}

/** Routing rules (no text of their own): the model id, the provider, the intent key and the policy / intent names. */
export function useAiRoutingRules() {
    return routingCatalog('/api/admin/v1/ai-routing-rules', useAiRoutingRulesStore(), 'ai_routing_rules.confirm_delete', {
        columns: ['model_key'],
        searchInTranslations: false,
        filterTranslationByLocale: false,
        searchInRelations: [
            { relation: 'provider', columns: ['name', 'key'] },
            { relation: 'intent', columns: ['key'], searchInRelationTranslations: true },
            { relation: 'policy', columns: ['country_code'], searchInRelationTranslations: true },
        ],
    });
}
