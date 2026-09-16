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
     * @return array{success: bool, message: string, content: ?string}
     */
    public function sendChat(AiProvider $provider, array $messages): array;
}
