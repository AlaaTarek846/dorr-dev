<?php

namespace Modules\AI\Services\World\Video;

/** Video generation as DORR AI WORLD needs it. Swappable so another vendor (or a test fake) can replace Veo. */
interface WorldVideoProvider
{
    /**
     * @param  list<array{mime: string, bytes: string}>  $references  face/place reference photos
     * @return array{success: bool, job_id: ?string, message: string, provider_id: ?int, model_key: ?string}
     */
    public function start(string $prompt, int $seconds, array $references = []): array;

    /** @return array{state: string, message: string} state: processing | completed | failed */
    public function poll(string $jobId): array;

    /** @return array{success: bool, message: string} */
    public function download(string $jobId, string $destinationPath): array;
}
