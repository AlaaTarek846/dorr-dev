<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatBusinessProfile;
use Modules\Chat\Services\BusinessService;

/**
 * My business tools: opening hours, welcome / away messages, and quick replies ("/").
 */
class BusinessController extends Controller
{
    public function __construct(private readonly BusinessService $business) {}

    public function show(Request $request)
    {
        $me = $request->user();

        return ApiResponse::success([
            'profile' => $this->business->profile($me)->present(),
            'quick_replies' => $this->business->quickReplies($me),
        ], __('api.retrieved'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'welcome_enabled' => ['sometimes', 'boolean'],
            'welcome_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'away_enabled' => ['sometimes', 'boolean'],
            'away_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'away_mode' => ['sometimes', Rule::in([ChatBusinessProfile::AWAY_ALWAYS, ChatBusinessProfile::AWAY_OUTSIDE_HOURS])],
            // 7 days, Sunday first.
            'hours' => ['sometimes', 'array', 'size:7'],
            'hours.*.open' => ['required_with:hours', 'boolean'],
            'hours.*.from' => ['required_with:hours', 'date_format:H:i'],
            'hours.*.to' => ['required_with:hours', 'date_format:H:i'],
            'timezone' => ['sometimes', 'timezone:all'],
        ]);

        return ApiResponse::success($this->business->updateProfile($request->user(), $data)->present(), __('api.updated'));
    }

    public function quickReplies(Request $request)
    {
        return ApiResponse::success($this->business->quickReplies($request->user()), __('api.retrieved'));
    }

    public function storeQuickReply(Request $request)
    {
        $data = $request->validate([
            'shortcut' => ['required', 'string', 'regex:/^\/?[\p{L}\p{N}_-]{1,32}$/u'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        return ApiResponse::created($this->business->addQuickReply($request->user(), $data['shortcut'], $data['body'])->present(), __('api.created'));
    }

    public function updateQuickReply(Request $request, int $quickReply)
    {
        $data = $request->validate([
            'shortcut' => ['sometimes', 'string', 'regex:/^\/?[\p{L}\p{N}_-]{1,32}$/u'],
            'body' => ['sometimes', 'string', 'max:4000'],
        ]);

        return ApiResponse::success($this->business->updateQuickReply($request->user(), $quickReply, $data)->present(), __('api.updated'));
    }

    public function destroyQuickReply(Request $request, int $quickReply)
    {
        $this->business->removeQuickReply($request->user(), $quickReply);

        return ApiResponse::success(null, __('api.deleted'));
    }
}
