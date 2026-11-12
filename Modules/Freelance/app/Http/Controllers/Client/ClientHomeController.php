<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Services\Home\ClientHome;
use Modules\User\Models\User;

/** The client's freelance hub in the Dorr user app (the client redesign, batch B): GET mobile/v1/freelance/home. */
class ClientHomeController extends Controller
{
    public function __construct(private readonly ClientHome $home) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user('user_api');

        return ApiResponse::success($this->home->for($me), __('api.retrieved'));
    }
}
