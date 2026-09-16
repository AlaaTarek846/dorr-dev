<?php

namespace Modules\AI\Services\Connectors\Contracts;

use Modules\AI\Models\AiProvider;

interface AiConnector
{
    /**
     * Attempt a lightweight call against the provider's API to verify the
     * stored credentials and settings actually work.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(AiProvider $provider): array;
}
