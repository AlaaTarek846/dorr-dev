import { createCatalogStore } from './createCatalogStore';

/** Filter counts of the offers / edit packs screen (reset when the screen switches kind). */
export const useAiSiteOffersStore = createCatalogStore('aiSiteOffers');
