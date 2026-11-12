import { useCatalog } from './useCatalog';
import {
    useAiBenchmarkCasesStore,
    useAiLanguageEvaluationsStore,
    useAiLanguageVariantsStore,
} from '../stores/aiQualityScreens';

/**
 * The AI language-variant, language-evaluation and benchmark-case lists - the same catalog list as useCountries:
 * search, All / Active / Inactive / Trash filters with counts, bulk delete, status toggle, restore / force delete.
 * Full counts (not lazy): the Trash filter only shows when its count is known.
 *
 * Each returns useCatalog()'s API; the rows are `items`. Every relation search names a column
 * (SearchFilterTrait matches every row when a relation's column list is empty).
 */
function qualityCatalog(apiUri, countsStore, confirmDeleteKey, searchDefaults) {
    return useCatalog({
        apiUri,
        countsStore,
        lazyCounts: false,
        confirmDeleteKey,
        searchDefaults,
        dataKey: 'items',
    });
}

/** Language variants: the code and style (technical) + the name in every language + the language's code. */
export function useAiLanguageVariants() {
    return qualityCatalog('/api/admin/v1/ai-language-variants', useAiLanguageVariantsStore(), 'ai_language_variants.confirm_delete', {
        columns: ['code', 'style'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
        searchInRelations: [
            { relation: 'language', columns: ['code'] },
        ],
    });
}

/** Language evaluations: the result and notes + the name in every language + the language / variant code. */
export function useAiLanguageEvaluations() {
    return qualityCatalog('/api/admin/v1/ai-language-evaluations', useAiLanguageEvaluationsStore(), 'ai_language_evaluations.confirm_delete', {
        columns: ['evaluation_status', 'notes'],
        searchInTranslations: true,
        filterTranslationByLocale: false,
        searchInRelations: [
            { relation: 'language', columns: ['code'] },
            { relation: 'variant', columns: ['code'] },
        ],
    });
}

/** Benchmark cases (no translations): the question, notes and the technical columns. */
export function useAiBenchmarkCases() {
    return qualityCatalog('/api/admin/v1/ai-benchmark-cases', useAiBenchmarkCasesStore(), 'ai_benchmark_cases.confirm_delete', {
        columns: ['prompt', 'notes', 'domain_key', 'task_type', 'country_code', 'language'],
    });
}
