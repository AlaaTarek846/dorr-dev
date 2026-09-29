<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Modules\User\Http\Requests\PhoneChangeRequest;
use Modules\User\Http\Requests\PhoneChangeVerifyRequest;
use Modules\User\Models\User;
use Modules\User\Services\PhoneChangeService;

/**
 * Changing the logged-in user's phone number: a code to the new number (proving it is reachable),
 * behind the wallet PIN when one already exists — see PhoneChangeService for the full guard list.
 */
class PhoneChangeController extends Controller
{
    public function __construct(private readonly PhoneChangeService $phoneChanges) {}

    public function start(PhoneChangeRequest $request)
    {
        /** @var User $user */
        $user = $request->user('user_api');

        $this->phoneChanges->start($user, $request->fullPhone(), $request->header('X-Wallet-Pin'));

        return ApiResponse::success(['sent' => true], __('api.phone_change_sent'));
    }

    public function confirm(PhoneChangeVerifyRequest $request)
    {
        /** @var User $user */
        $user = $request->user('user_api');

        $this->phoneChanges->confirm($user, $request->validated('code'), $request->ip());

        return ApiResponse::success(['phone' => $user->fresh()->phone], __('api.phone_changed'));
    }
}
