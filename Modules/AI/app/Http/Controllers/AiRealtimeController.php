<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Modules\AI\Services\AiRealtimeService;

/**
 * Phase 7 (realtime voice) - shared between the User and Provider guards,
 * same pattern as AiChatController (see its own docblock on owner()).
 */
class AiRealtimeController extends Controller
{
    public function __construct(protected AiRealtimeService $service) {}

    public function createSession(Request $request)
    {
        $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->service->createSession(
            $this->owner($request),
            $request->input('conversation_id'),
            $request->input('instructions'),
        );
    }

    public function endSession(Request $request, int|string $session)
    {
        $request->validate([
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        return $this->service->endSession(
            $this->owner($request),
            $session,
            $request->input('duration_seconds'),
        );
    }

    protected function owner(Request $request): Authenticatable
    {
        return $request->user('user_api') ?? $request->user('provider_api');
    }
}
