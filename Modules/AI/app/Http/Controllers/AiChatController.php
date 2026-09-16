<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\AI\Http\Requests\AiChatMessageRequest;
use Modules\AI\Services\AiChatService;

class AiChatController extends Controller
{
    public function __construct(protected AiChatService $service) {}

    public function status(Request $request)
    {
        return $this->service->activeProviderStatus();
    }

    public function index(Request $request)
    {
        return $this->service->listConversations($request->user('user_api'));
    }

    public function store(Request $request)
    {
        return $this->service->createConversation($request->user('user_api'));
    }

    public function show(Request $request, int|string $conversation)
    {
        return $this->service->showConversation($request->user('user_api'), $conversation);
    }

    public function destroy(Request $request, int|string $conversation)
    {
        return $this->service->deleteConversation($request->user('user_api'), $conversation);
    }

    public function sendMessage(AiChatMessageRequest $request, int|string $conversation)
    {
        return $this->service->sendMessage(
            $request->user('user_api'),
            $conversation,
            $request->validated('message'),
        );
    }
}
