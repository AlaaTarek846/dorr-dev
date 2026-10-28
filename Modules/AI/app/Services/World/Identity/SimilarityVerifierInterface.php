<?php

namespace Modules\AI\Services\World\Identity;

/** Compares two face images. Swappable so another vendor (or a test fake) can replace AWS Rekognition. */
interface SimilarityVerifierInterface
{
    public function isConfigured(): bool;

    /** @return float|null similarity 0-100, or null when no face could be found in one of the images */
    public function compare(string $referenceBytes, string $candidateBytes): ?float;
}
