import { createCatalogStore } from './createCatalogStore';

/** Filter counts (All / Active / Inactive / Trash) of the AI routing screens - one store per screen. */
export const useAiIntentsStore = createCatalogStore('aiIntents');
export const useAiLearnedIntentsStore = createCatalogStore('aiLearnedIntents');
export const useAiRoutingPoliciesStore = createCatalogStore('aiRoutingPolicies');
export const useAiGatewaysStore = createCatalogStore('aiGateways');
export const useAiRoutingRulesStore = createCatalogStore('aiRoutingRules');
