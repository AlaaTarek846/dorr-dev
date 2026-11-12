import { createCatalogStore } from './createCatalogStore';

/** Filter counts (All / Active / Inactive / Trash) of the AI language-variant, language-evaluation and benchmark-case screens - one store per screen. */
export const useAiLanguageVariantsStore = createCatalogStore('aiLanguageVariants');
export const useAiLanguageEvaluationsStore = createCatalogStore('aiLanguageEvaluations');
export const useAiBenchmarkCasesStore = createCatalogStore('aiBenchmarkCases');
