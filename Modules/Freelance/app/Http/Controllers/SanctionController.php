<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Services\Moderation\Sanctions;

/**
 * My warnings, limits and suspensions (FL-708), one controller for both prefixes: `GET sanctions` (what limits me now and my sanctions of
 * the last year) and `POST sanctions/{uuid}/appeal {note}` (once, within the days).
 */
class SanctionController extends Controller
{
    public function __construct(private readonly Sanctions $sanctions) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->sanctions->mine($this->me($request)), __('api.retrieved'));
    }

    public function appeal(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']]);
        $me = $this->me($request);
        $this->sanctions->appeal($me, $uuid, $data['note']);

        return ApiResponse::created($this->sanctions->mine($me), __('freelance.appeal_sent'));
    }

    private function me(Request $request): \Illuminate\Database\Eloquent\Model
    {
        return $request->user('freelance_api') ?? $request->user('user_api');
    }
}
