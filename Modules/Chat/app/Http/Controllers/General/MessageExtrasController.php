<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\LinkPreviewService;
use Modules\Chat\Services\MessageExtrasService;
use Modules\Chat\Services\MessageService;
use Modules\Chat\Services\MoneyRequestService;

/**
 * Polls (vote / who voted), view-once media (open), live location (move / stop / mine) and link
 * cards for the composer — the parts of a message that keep changing after it's sent.
 */
class MessageExtrasController extends Controller
{
    public function __construct(
        private readonly MessageExtrasService $extras,
        private readonly MessageService $messages,
    ) {}

    public function vote(Request $request, ChatMessage $message)
    {
        $data = $request->validate(['options' => ['present', 'array', 'max:12'], 'options.*' => ['integer']]);
        $me = $request->user();

        return ApiResponse::success($this->messages->presentOne($me, $this->extras->vote($me, $message, $data['options'])), __('api.updated'));
    }

    public function votes(Request $request, ChatMessage $message)
    {
        return ApiResponse::success($this->extras->voters($request->user(), $message), __('api.retrieved'));
    }

    public function open(Request $request, ChatMessage $message)
    {
        return ApiResponse::success(['attachments' => $this->extras->open($request->user(), $message)], __('api.retrieved'));
    }

    public function moveLive(Request $request, ChatMessage $message)
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        $this->extras->moveLive($request->user(), $message, (float) $data['latitude'], (float) $data['longitude'], isset($data['accuracy']) ? (float) $data['accuracy'] : null);

        return ApiResponse::success(null, 'OK');
    }

    public function stopLive(Request $request, ChatMessage $message)
    {
        $me = $request->user();

        return ApiResponse::success($this->messages->presentOne($me, $this->extras->stopLive($me, $message)), __('api.updated'));
    }

    public function myLive(Request $request)
    {
        return ApiResponse::success($this->extras->myLive($request->user()), __('api.retrieved'));
    }

    /**
     * Pay a money request, or my share of a bill split — a real wallet transfer (X-Wallet-Pin
     * checked by RequiresWalletPin on the route).
     */
    public function pay(Request $request, ChatMessage $message, MoneyRequestService $money)
    {
        $me = $request->user();

        return ApiResponse::success($this->messages->presentOne($me, $money->pay($me, $message)), __('chat.money_paid'));
    }

    public function declineRequest(Request $request, ChatMessage $message, MoneyRequestService $money)
    {
        $me = $request->user();

        return ApiResponse::success($this->messages->presentOne($me, $money->decline($me, $message)), __('api.updated'));
    }

    public function cancelRequest(Request $request, ChatMessage $message, MoneyRequestService $money)
    {
        $me = $request->user();

        return ApiResponse::success($this->messages->presentOne($me, $money->cancel($me, $message)), __('api.updated'));
    }

    /**
     * The card for a link while it's still being typed (the composer shows it above the keyboard).
     */
    public function linkPreview(Request $request, LinkPreviewService $previews)
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2000']]);
        $url = $previews->firstUrl($data['url']);

        return ApiResponse::success($url === null ? null : $previews->forUrl($url), __('api.retrieved'));
    }
}
