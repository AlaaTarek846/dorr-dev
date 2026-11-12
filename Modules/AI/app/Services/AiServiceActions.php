<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\AI\Contracts\AiServiceActionProvider;
use Throwable;

/**
 * DORR's own services offered inside DORR AI's answers. Modules register a provider
 * ({@see AiServiceActionProvider}); the chat asks them for guidance before the model answers and for cards
 * after it, stored on the answer (`ai_messages.actions`). A provider that fails is skipped — the chat answer
 * never depends on it.
 */
class AiServiceActions
{
    /** @var list<class-string<AiServiceActionProvider>> */
    private array $providers = [];

    /** @param  class-string<AiServiceActionProvider>  $provider */
    public function register(string $provider): void
    {
        if (! in_array($provider, $this->providers, true)) {
            $this->providers[] = $provider;
        }
    }

    public function guidance(Authenticatable $owner, string $message): ?string
    {
        $parts = [];
        foreach ($this->providers as $provider) {
            try {
                $text = app($provider)->guidance($owner, $message);
            } catch (Throwable $e) {
                report($e);
                $text = null;
            }
            if (is_string($text) && trim($text) !== '') {
                $parts[] = trim($text);
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /** @return list<array<string, mixed>> */
    public function actions(Authenticatable $owner, string $message): array
    {
        $cards = [];
        foreach ($this->providers as $provider) {
            try {
                foreach (app($provider)->actions($owner, $message) as $card) {
                    $cards[] = $card;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $cards;
    }
}
