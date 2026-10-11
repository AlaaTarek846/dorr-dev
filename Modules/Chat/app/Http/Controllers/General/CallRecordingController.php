<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\ChatCall;
use Modules\Chat\Services\CallRecordingService;

/**
 * Call recording (spec 92): ask everyone in the call, answer, stop — and play the recordings I
 * agreed to. Each change also reaches everyone in the call as `chat.call.recording`.
 */
class CallRecordingController extends Controller
{
    public function __construct(private readonly CallRecordingService $recordings) {}

    /** POST calls/{call}/recording — ask (my asking is my yes). */
    public function store(Request $request, ChatCall $call)
    {
        return ApiResponse::created($this->recordings->state($this->recordings->request($request->user(), $call)), __('api.created'));
    }

    /** POST calls/{call}/recording/consent — `{accept: bool}` */
    public function consent(Request $request, ChatCall $call)
    {
        $accept = (bool) $request->validate(['accept' => ['required', 'boolean']])['accept'];

        return ApiResponse::success($this->recordings->state($this->recordings->answer($request->user(), $call, $accept)), __('api.updated'));
    }

    /** POST calls/{call}/recording/stop — stop it, or take the request back. */
    public function stop(Request $request, ChatCall $call)
    {
        return ApiResponse::success($this->recordings->state($this->recordings->stop($request->user(), $call)), __('api.updated'));
    }

    /** GET calls/{call}/recordings — the ones I agreed to; `url` (a few minutes) once saved. */
    public function index(Request $request, ChatCall $call)
    {
        return ApiResponse::success($this->recordings->listFor($request->user(), $call), __('api.retrieved'));
    }
}
