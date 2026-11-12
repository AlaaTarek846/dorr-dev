import { createCatalogStore } from './createCatalogStore';

/** Filter counts (All / Active / Inactive / Trash) of the AI feature-flag, domain-policy and safety screens - one store per screen. */
export const useAiFeatureFlagsStore = createCatalogStore('aiFeatureFlags');
export const useAiDomainPoliciesStore = createCatalogStore('aiDomainPolicies');
export const useAiSafetyPoliciesStore = createCatalogStore('aiSafetyPolicies');
export const useAiSafetyRulesStore = createCatalogStore('aiSafetyRules');
