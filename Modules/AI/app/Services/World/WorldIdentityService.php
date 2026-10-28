<?php

namespace Modules\AI\Services\World;

use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\World\AiWorldIdentityProfile;
use Modules\AI\Models\World\AiWorldProject;
use Modules\AI\Models\World\AiWorldScene;
use Modules\AI\Models\World\AiWorldSceneVersion;
use Modules\AI\Services\World\Identity\SimilarityVerifierInterface;
use Modules\AI\Services\World\Identity\WorldFrameExtractor;

/**
 * Measures how well a generated clip keeps the person's face. It never claims a perfect match: it returns a
 * similarity score (average of the best match per sampled frame) that is compared with an admin-set threshold.
 */
class WorldIdentityService
{
    public function __construct(protected SimilarityVerifierInterface $verifier, protected WorldFrameExtractor $frames) {}

    public function applies(AiWorldProject $project): bool
    {
        return $project->identity_profile_id !== null && $this->verifier->isConfigured();
    }

    /** @return float|null 0-100, 0 when no face appears in any sampled frame, null when the check could not run */
    public function score(AiWorldProject $project, AiWorldScene $scene, AiWorldSceneVersion $version): ?float
    {
        $profile = AiWorldIdentityProfile::query()->where('status', AiWorldIdentityProfile::ACTIVE)->find($project->identity_profile_id);

        if ($profile === null || $version->video_path === null) {
            return null;
        }

        $references = [];

        foreach ((array) $profile->reference_files as $ref) {
            if (isset($ref['path']) && Storage::disk('local')->exists($ref['path'])) {
                $references[] = Storage::disk('local')->get($ref['path']);
            }
        }

        $frames = $this->frames->frames(Storage::disk('local')->path($version->video_path), $scene->seconds);

        if ($references === [] || $frames === []) {
            return null;
        }

        try {
            $scores = [];

            foreach ($frames as $frame) {
                $best = 0.0;

                foreach ($references as $reference) {
                    $best = max($best, (float) $this->verifier->compare($reference, $frame));
                }

                $scores[] = $best;
            }
        } catch (\Throwable) {
            return null;
        }

        return round(array_sum($scores) / count($scores), 2);
    }
}
