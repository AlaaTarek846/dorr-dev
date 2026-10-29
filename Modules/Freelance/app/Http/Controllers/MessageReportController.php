<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Services\Chat\MessageReports;

/** POST chat/messages/{message}/report {reason, note?} (FL-709), one controller for both prefixes: the other person's message in my room. */
class MessageReportController extends Controller
{
    public function store(Request $request, MessageReports $reports, int $message): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', Rule::in(MessageReports::REASONS)], 'note' => ['nullable', 'string', 'max:500']]);
        $me = $request->user('freelance_api') ?? $request->user('user_api');
        $reports->report($me, $message, $data['reason'], $data['note'] ?? null);

        return ApiResponse::created(null, __('freelance.report_message_sent'));
    }
}
