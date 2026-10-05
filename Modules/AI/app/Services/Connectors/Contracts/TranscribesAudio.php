<?php

namespace Modules\AI\Services\Connectors\Contracts;

use Modules\AI\Models\AiProvider;

/**
 * A provider that can turn speech into text (OpenAI and Groq's Whisper, Gemini). Anthropic's API
 * takes no audio, so its connector doesn't implement this.
 */
interface TranscribesAudio
{
    /**
     * @return array{success: bool, message: string, content: ?string}
     */
    public function transcribe(AiProvider $provider, string $path, string $mime): array;
}
