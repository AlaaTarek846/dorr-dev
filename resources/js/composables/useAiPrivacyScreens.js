import { useCatalog } from './useCatalog';
import {
    useAiConversationInstructionsStore,
    useAiDataPoliciesStore,
    useAiProjectInstructionsStore,
    useAiProviderDataRulesStore,
    useAiSecurityPoliciesStore,
} from '../stores/aiPrivacyScreens';

/**
 * The AI security / data / instruction screens' lists (security policies, data policies, provider data rules,
 * conversation instructions, project instructions) - the same catalog list as useCountries: search, All / Active /
 * Inactive / Trash filters with counts, bulk delete, status toggle, restore / force delete. Full counts (not lazy):
 * the Trash filter only shows when its count is known.
 *
 * Each returns useCatalog()'s API; the rows are `items`. Every relation search names a column
 * (SearchFilterTrait matches every row when a relation's column list is empty).
 */
function privacyCatalog(apiUri, countsStore, confirmDeleteKey, searchDefaults) {
    return useCatalog({
        apiUri,
        countsStore,
        lazyCounts: false,
        confirmDeleteKey,
        searchDefaults,
        dataKey: 'items',
    });
}

/** Security policies: the name / description in every language. */
export function useAiSecurityPolicies() {
    return privacyCatalog('/api/admin/v1/ai-security-policies', useAiSecurityPoliciesStore(), 'ai_security_policies.confirm_delete', {
        columns: ['id'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
    });
}

/** Data policies: the classification + the name / description in every language. */
export function useAiDataPolicies() {
    return privacyCatalog('/api/admin/v1/ai-data-policies', useAiDataPoliciesStore(), 'ai_data_policies.confirm_delete', {
        columns: ['data_classification'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
    });
}

/** Provider data rules: the provider's name / key. */
export function useAiProviderDataRules() {
    return privacyCatalog('/api/admin/v1/ai-provider-data-rules', useAiProviderDataRulesStore(), 'ai_provider_data_rules.confirm_delete', {
        columns: ['id'],
        searchInRelations: [
            { relation: 'provider', columns: ['name', 'key'] },
        ],
    });
}

/** Conversation instructions: the instruction text and source + the conversation's title. */
export function useAiConversationInstructions() {
    return privacyCatalog('/api/admin/v1/ai-conversation-instructions', useAiConversationInstructionsStore(), 'ai_conversation_instructions.confirm_delete', {
        columns: ['instruction', 'source_type'],
        searchInRelations: [
            { relation: 'conversation', columns: ['title'] },
        ],
    });
}

/** Project instructions: the instruction text + the project's name. */
export function useAiProjectInstructions() {
    return privacyCatalog('/api/admin/v1/ai-project-instructions', useAiProjectInstructionsStore(), 'ai_project_instructions.confirm_delete', {
        columns: ['instruction'],
        searchInRelations: [
            { relation: 'project', columns: ['name'] },
        ],
    });
}
