<?php

namespace Modules\AI\Services\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\Connectors\Contracts\AiConnector;
use Throwable;

abstract class AbstractHttpConnector implements AiConnector
{
    abstract protected function providerKey(): string;

    abstract protected function defaultBaseUrl(): string;

    /**
     * @return array{success: bool, message: string, models: list<string>}
     */
    abstract public function testConnection(AiProvider $provider): array;

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{success: bool, message: string, content: ?string}
     */
    abstract public function sendChat(AiProvider $provider, array $messages): array;

    protected function baseUrl(AiProvider $provider): string
    {
        $baseUrl = $provider->base_url ?: config("ai.providers.{$this->providerKey()}.base_url") ?: $this->defaultBaseUrl();

        return rtrim((string) $baseUrl, '/');
    }

    protected function client(): PendingRequest
    {
        return Http::timeout((int) config('ai.request_timeout', 20))->acceptJson();
    }

    /**
     * @param  list<string>  $models
     */
    protected function success(string $message, array $models = []): array
    {
        return ['success' => true, 'message' => $message, 'models' => $models, 'content' => null];
    }

    protected function failure(string $message): array
    {
        return ['success' => false, 'message' => $message, 'models' => [], 'content' => null];
    }

    protected function chatReply(string $content): array
    {
        return ['success' => true, 'message' => '', 'models' => [], 'content' => $content];
    }

    /**
     * Run the given callback and normalize connection-level exceptions into
     * a failed result instead of letting them bubble up. Shared by
     * testConnection() and sendChat() implementations.
     */
    protected function attempt(callable $callback): array
    {
        try {
            return $callback();
        } catch (ConnectionException $exception) {
            return $this->failure(__('ai.connection_failed', ['message' => $exception->getMessage()]));
        } catch (Throwable $exception) {
            return $this->failure(__('ai.unexpected_error', ['message' => $exception->getMessage()]));
        }
    }

    protected function errorMessageFromResponse(Response $response): string
    {
        $body = $response->json();

        $message = $body['error']['message']
            ?? $body['message']
            ?? null;

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return __('ai.http_error', ['status' => $response->status()]);
    }
}
