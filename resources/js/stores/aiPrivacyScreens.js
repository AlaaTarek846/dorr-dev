import { createCatalogStore } from './createCatalogStore';

/** Filter counts (All / Active / Inactive / Trash) of the AI security / data / instruction screens - one store per screen. */
export const useAiSecurityPoliciesStore = createCatalogStore('aiSecurityPolicies');
export const useAiDataPoliciesStore = createCatalogStore('aiDataPolicies');
export const useAiProviderDataRulesStore = createCatalogStore('aiProviderDataRules');
export const useAiConversationInstructionsStore = createCatalogStore('aiConversationInstructions');
export const useAiProjectInstructionsStore = createCatalogStore('aiProjectInstructions');
