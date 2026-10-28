<?php

namespace Modules\AI\Services\Ar;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Exceptions\AiArException;
use Modules\AI\Models\Ar\AiArAsset;
use Modules\AI\Models\Ar\AiArExperience;
use Modules\AI\Models\Ar\AiArExperienceVersion;
use Modules\AI\Models\Ar\AiArSetting;

/**
 * Experiences are scenes of placed models. Saving never overwrites: each save is a new version, and every item
 * pins the asset VERSION it was placed with, so a new model upload cannot change an old experience.
 * Any service (decor, education, a test harness) uses this same class: the engine does not know the specialty.
 */
class AiArExperienceService
{
    public function __construct(protected AiArAssetService $assets) {}

    public function create(Authenticatable $owner, ?int $countryId, string $type, string $title, ?string $sourceService = null): AiArExperience
    {
        if (! AiArSetting::current()->is_enabled) {
            throw new AiArException('disabled', 403);
        }

        if (! in_array($type, AiArExperience::TYPES, true)) {
            throw new AiArException('type_invalid', 422);
        }

        return AiArExperience::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'country_id' => $countryId,
            'type' => $type,
            'title' => $title,
            'status' => 'draft',
            'source_service' => $sourceService,
        ]);
    }

    /**
     * @param  list<array{asset_id: int, transform: array{position: list<float>, rotation: list<float>, scale: list<float>}, tint?: ?string, visible?: bool, anchor_type?: string, label?: ?string, info?: ?array}>  $items
     */
    public function saveScene(Authenticatable $owner, AiArExperience $experience, array $items, ?string $note = null): AiArExperienceVersion
    {
        if (in_array($experience->status, ['submitted', 'accepted'], true)) {
            throw new AiArException('experience_locked', 409);
        }

        $resolved = [];

        foreach ($items as $item) {
            $asset = AiArAsset::query()->find($item['asset_id']);

            if ($asset === null || ! $this->assets->canView($owner, $asset)) {
                throw new AiArException('asset_unavailable', 422);
            }

            $resolved[] = $item + ['asset_version_id' => $asset->current_version_id];
        }

        return DB::transaction(function () use ($experience, $resolved, $note) {
            $version = $experience->versions()->create(['version_no' => ((int) $experience->versions()->max('version_no')) + 1, 'note' => $note]);

            foreach ($resolved as $item) {
                $version->items()->create([
                    'asset_id' => $item['asset_id'],
                    'asset_version_id' => $item['asset_version_id'],
                    'transform' => $item['transform'],
                    'tint' => $item['tint'] ?? null,
                    'visible' => $item['visible'] ?? true,
                    'anchor_type' => $item['anchor_type'] ?? 'plane',
                    'label' => $item['label'] ?? null,
                    'info' => isset($item['info']) ? (array_intersect_key($item['info'], array_flip(['title', 'description', 'price_minor', 'url'])) ?: null) : null,
                ]);
            }

            $experience->forceFill(['current_version_id' => $version->id, 'status' => in_array($experience->status, ['draft', 'approved'], true) ? 'active' : $experience->status])->save();

            return $version->load('items');
        });
    }
}
