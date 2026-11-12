import { useCatalog } from './useCatalog';
import {
    useAiDomainPoliciesStore,
    useAiFeatureFlagsStore,
    useAiSafetyPoliciesStore,
    useAiSafetyRulesStore,
} from '../stores/aiControlScreens';

/**
 * The AI control screens' lists (feature flags, domain policies, safety policies, safety rules) - the same catalog
 * list as useCountries: search, All / Active / Inactive / Trash filters with counts, bulk delete, status toggle,
 * restore / force delete. Full counts (not lazy): the Trash filter only shows when its count is known.
 *
 * Each returns useCatalog()'s API; the rows are `items`. Every relation search names a column
 * (SearchFilterTrait matches every row when a relation's column list is empty).
 */
function controlCatalog(apiUri, countsStore, confirmDeleteKey, searchDefaults) {
    return useCatalog({
        apiUri,
        countsStore,
        lazyCounts: false,
        confirmDeleteKey,
        searchDefaults,
        dataKey: 'items',
    });
}

/** Feature flags: the key and scope (technical) + the description in every language + the provider's name / key. */
export function useAiFeatureFlags() {
    return controlCatalog('/api/admin/v1/ai-feature-flags', useAiFeatureFlagsStore(), 'ai_feature_flags.confirm_delete', {
        columns: ['key', 'model_key', 'tool_key', 'country_code', 'domain', 'environment'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
        searchInRelations: [
            { relation: 'provider', columns: ['name', 'key'] },
        ],
    });
}

/** Domain policies: the domain key + the name / description in every language. */
export function useAiDomainPolicies() {
    return controlCatalog('/api/admin/v1/ai-domain-policies', useAiDomainPoliciesStore(), 'ai_domain_policies.confirm_delete', {
        columns: ['domain_key'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
    });
}

/** Safety policies: the name / description in every language. */
export function useAiSafetyPolicies() {
    return controlCatalog('/api/admin/v1/ai-safety-policies', useAiSafetyPoliciesStore(), 'ai_safety_policies.confirm_delete', {
        columns: ['risk_level'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
    });
}

/** Safety rules: the action + the rule's name in every language + its policy's name. */
export function useAiSafetyRules() {
    return controlCatalog('/api/admin/v1/ai-safety-rules', useAiSafetyRulesStore(), 'ai_safety_rules.confirm_delete', {
        columns: ['action'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
        searchInRelations: [
            { relation: 'policy', columns: ['risk_level'], searchInRelationTranslations: true, filterTranslationByLocale: false },
        ],
    });
}
