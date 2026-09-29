<?php

namespace Modules\AI\Services\Connectors\Contracts;

use Modules\AI\Models\AiProvider;

interface AiConnector
{
    /**
     * Attempt a lightweight call against the provider's API to verify the
     * stored credentials and settings actually work.
     *
     * @return array{success: bool, message: string, models: list<string>}
     */
    public function testConnection(AiProvider $provider): array;

    /**
     * Send a chat completion request and normalize the reply.
     *
     * $messages is a canonical, provider-agnostic list of
     * ['role' => 'system'|'user'|'assistant', 'content' => string] entries,
     * in chronological order. Each connector translates this into whatever
     * shape its own API expects.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  bool  $useWebSearch  When true, the caller has already
     *   confirmed (via AiRequiredCapabilityResolver + the routing
     *   engine's capability match) that this exact request needs live
     *   web results AND that $provider's model is actually registered
     *   with the "web_search" capability. A connector with no real
     *   hosted web-search integration for its provider simply ignores
     *   this flag rather than faking search results - matching the
     *   embed()/editImage() honesty precedent below.
     * @return array{success: bool, message: string, content: ?string}
     */
    public function sendChat(AiProvider $provider, array $messages, bool $useWebSearch = false): array;

    /**
     * Turn a piece of text into an embedding vector for the Knowledge
     * base / RAG retriever (v2.0 doc, section 5.3). Not every provider
     * exposes an embeddings API - a connector without real support
     * returns success=false with an explanatory message rather than
     * faking a vector, so the retriever can fall back to lexical-only
     * search instead of silently comparing meaningless numbers.
     *
     * @return array{success: bool, message: string, vector: ?list<float>}
     */
    public function embed(AiProvider $provider, string $text): array;

    /**
     * Edit an existing image from a text instruction (e.g. "make it
     * green") and return the resulting image bytes. Not every provider
     * exposes a real image-editing API - a connector without real
     * support returns success=false with an explanatory message rather
     * than faking a picture, matching the embed() precedent above.
     *
     * @return array{success: bool, message: string, image: ?array{base64: string, mime: string}}
     */
    public function editImage(AiProvider $provider, string $modelKey, string $imageBytes, string $imageMime, string $prompt): array;

    /**
     * Create a brand new image from a text prompt alone (no source image) -
     * e.g. "a professional photo of the Giza pyramids at sunset, cinematic
     * style". Not every provider exposes a real text-to-image API - a
     * connector without real support returns success=false with an
     * explanatory message rather than faking a picture, matching the
     * editImage()/embed() precedent above.
     *
     * @return array{success: bool, message: string, image: ?array{base64: string, mime: string}}
     */
    public function generateImage(AiProvider $provider, string $modelKey, string $prompt): array;

    /**
     * Transcribe spoken audio (a customer's voice message) into plain
     * text. Not every provider exposes a real speech-to-text API - a
     * connector without real support returns success=false with an
     * explanatory message rather than faking a transcript, matching the
     * embed()/editImage() precedent above.
     *
     * @return array{success: bool, message: string, text: ?string}
     */
    public function transcribeAudio(AiProvider $provider, string $modelKey, string $audioBytes, string $audioMime): array;

    /**
     * Turn a text reply into a spoken audio reply (text-to-speech). Not
     * every provider exposes a real TTS API - a connector without real
     * support returns success=false with an explanatory message rather
     * than faking audio, matching the precedent above.
     *
     * @return array{success: bool, message: string, audio: ?array{base64: string, mime: string}}
     */
    public function synthesizeSpeech(AiProvider $provider, string $modelKey, string $text): array;

    /**
     * Master-spec section 20/21: mint a short-lived Realtime session
     * credential the CLIENT (the Android app) then connects to OpenAI
     * with DIRECTLY over WebRTC - this backend call never carries the
     * live audio itself, only ever creates the ephemeral, scoped
     * credential. That split is the entire point: the caller (see
     * AiRealtimeService) never learns or forwards the real
     * OPENAI_API_KEY to the client, matching section 36's explicit
     * "never expose OPENAI_API_KEY to Android" rule, while the actual
     * audio never has to round-trip through this Laravel process at
     * all. Not every provider exposes a real Realtime API - a connector
     * without real support returns success=false with an explanatory
     * message rather than faking a session, matching the
     * embed()/editImage()/transcribeAudio() precedent above.
     *
     * @param  array{voice?: string, instructions?: ?string}  $options
     * @return array{success: bool, message: string, session: ?array{client_secret: string, expires_at: ?int, model: string}}
     */
    public function createRealtimeSession(AiProvider $provider, string $modelKey, array $options = []): array;
}
