<?php

namespace Modules\AI\Services;

use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\AI\Http\Resources\AiConversationResource;
use Modules\AI\Http\Resources\AiMessageResource;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiMessage;
use Modules\AI\Repositories\AiConversationRepository;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\User\Models\User;

class AiChatService
{
    public function __construct(
        protected AiConversationRepository $conversations,
        protected AiProviderRepository $providers,
        protected AiGateway $gateway,
    ) {}

    public function listConversations(User $user): JsonResponse
    {
        return ApiResponse::success(
            AiConversationResource::collection($this->conversations->listForUser($user->id)),
            __('api.retrieved'),
        );
    }

    public function createConversation(User $user): JsonResponse
    {
        $conversation = $this->conversations->createForUser($user->id);

        return ApiResponse::created(
            new AiConversationResource($conversation),
            __('api.created'),
        );
    }

    public function showConversation(User $user, int|string $id): JsonResponse
    {
        $conversation = $this->conversations->findForUser($user->id, $id);

        return ApiResponse::success(
            new AiConversationResource($conversation),
            __('api.retrieved'),
        );
    }

    public function deleteConversation(User $user, int|string $id): JsonResponse
    {
        $this->conversations->deleteForUser($user->id, $id);

        return ApiResponse::noContent(__('api.deleted'));
    }

    /**
     * Lets the frontend know up front whether there is anything to chat
     * with at all, so it can show a friendly notice instead of a wall of
     * failed-message bubbles.
     */
    public function activeProviderStatus(): JsonResponse
    {
        $provider = $this->providers->resolveActiveForChat();

        return ApiResponse::success([
            'available' => $provider !== null,
            'provider_key' => $provider?->key,
            'provider_name' => $provider?->name,
        ], __('api.retrieved'));
    }

    public function sendMessage(User $user, int|string $conversationId, string $content): JsonResponse
    {
        $provider = $this->providers->resolveActiveForChat();

        if (! $provider) {
            return ApiResponse::error(__('ai.no_active_provider'), 422);
        }

        $conversation = $this->conversations->findForUser($user->id, $conversationId);

        $userMessage = $conversation->messages()->create([
            'role' => AiMessage::ROLE_USER,
            'content' => $content,
        ]);

        if (blank($conversation->title)) {
            $conversation->title = Str::limit($content, 60);
        }

        $conversation->provider_key = $provider->key;
        $conversation->save();

        $history = $this->buildHistory($conversation, $user);
        $result = $this->gateway->chat($provider, $history);

        $assistantMessage = $conversation->messages()->create([
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => $result['success'] ? $result['content'] : $result['message'],
            'provider_key' => $provider->key,
            'model' => $provider->model,
            'is_error' => ! $result['success'],
        ]);

        return ApiResponse::success([
            'user_message' => new AiMessageResource($userMessage),
            'assistant_message' => new AiMessageResource($assistantMessage),
            'conversation' => new AiConversationResource($conversation->refresh()),
        ], $result['success'] ? __('api.created') : __('ai.test_failed'));
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    protected function buildHistory(AiConversation $conversation, User $user): array
    {
        $limit = (int) config('ai.chat.history_limit', 30);

        // Querying AiMessage directly (rather than through
        // $conversation->messages(), which has its own orderBy('created_at')
        // baked in) avoids Laravel *accumulating* both orderBy calls into
        // "ORDER BY created_at asc, created_at desc" - which resolved to
        // ascending anyway, and then got flipped backwards by ->reverse()
        // below, silently sending the whole conversation to the model in
        // reverse chronological order.
        $messages = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('is_error', false)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn (AiMessage $message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();

        foreach (array_reverse($this->systemMessages($user)) as $systemMessage) {
            array_unshift($messages, $systemMessage);
        }

        return $messages;
    }

    /**
     * The base system prompt plus a short, factual profile of the logged-in
     * user, so the assistant already knows who it's talking to instead of
     * asking for their name, phone, etc. on every conversation.
     *
     * @return list<array{role: string, content: string}>
     */
    protected function systemMessages(User $user): array
    {
        $messages = [];
        $prompt = config('ai.chat.system_prompt');

        if (filled($prompt)) {
            $messages[] = ['role' => AiMessage::ROLE_SYSTEM, 'content' => $prompt];
        }

        $profile = $this->userProfileContext($user);

        if ($profile !== '') {
            $messages[] = ['role' => AiMessage::ROLE_SYSTEM, 'content' => $profile];
        }

        return $messages;
    }

    protected function userProfileContext(User $user): string
    {
        $lines = ['Here is what you already know about the user you are talking to - never ask them for it again:'];

        $lines[] = '- Name: '.$user->name;

        if (filled($user->email)) {
            $lines[] = '- Email: '.$user->email;
        }

        if (filled($user->phone)) {
            $lines[] = '- Phone: '.$user->phone;
        }

        if ($user->gender) {
            $lines[] = '- Gender: '.($user->gender->value ?? $user->gender);
        }

        $country = $user->country()->with('translation')->first();

        if ($country) {
            $lines[] = '- Country: '.($country->translatedName() ?? $country->code);
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }
}
