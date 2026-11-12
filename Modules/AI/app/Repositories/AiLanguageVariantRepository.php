<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Illuminate\Support\Collection;
use Modules\AI\Models\AiLanguageVariant;

/**
 * AI language variants as a catalog (like CountryRepository): name synced from `translations[]`, status toggle, bulk
 * delete, trash / restore / force delete, and the variant picker of the language evaluations form and the user
 * language preferences screen.
 */
class AiLanguageVariantRepository extends TranslatableRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['language.translations', 'translations', 'translation'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'language_id' => 'asc',
        'id' => 'asc',
    ];

    /**
     * Moving a variant to the trash is allowed: it stops being offered and applied (like switching it off) and the
     * preferences / evaluations that picked it keep it for a restore. Deleting it for good is blocked while a user
     * preference or an evaluation points at it (the database would silently clear their variant).
     *
     * @var list<string>
     */
    protected array $deleteBlockRelations = [];

    /** @var list<string> */
    protected array $forceDeleteBlockRelations = ['preferences', 'evaluations'];

    public function __construct(AiLanguageVariant $model)
    {
        $this->model = $model;
    }

    /**
     * Variant picker of the language evaluations form and the user language preferences screen: every variant not in
     * the trash - switched-off ones too (flagged by `status`), so a record that already uses one still shows it.
     */
    public function dropdown(): Collection
    {
        return $this->query()
            ->with('language.translations')
            ->orderBy('language_id')
            ->orderBy('id')
            ->get()
            ->map(fn (AiLanguageVariant $variant) => [
                'id' => $variant->id,
                'code' => $variant->code,
                'name' => $variant->name,
                'style' => $variant->style,
                'is_default' => (bool) $variant->is_default,
                'language_id' => $variant->language_id,
                'language' => $variant->language ? [
                    'id' => $variant->language->id,
                    'code' => $variant->language->code,
                    'name' => $variant->language->translatedName(),
                ] : null,
                'status' => (bool) $variant->status,
                'is_active' => (bool) $variant->status,
            ])
            ->values();
    }

    /**
     * @param  list<string>  $relations
     */
    public function forceDestroy(int|string $id, array $relations = []): bool
    {
        return parent::forceDestroy($id, $relations === [] ? $this->forceDeleteBlockRelations : $relations);
    }
}
