<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Services\Security\SecurityLog;
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

        $oldPhone = (string) $user->phone;
        $this->phoneChanges->confirm($user, $request->validated('code'), $request->ip());
        $newPhone = (string) $user->fresh()->phone;

        app(SecurityLog::class)->record($user, SecurityEvent::PHONE_CHANGED, [
            'from' => '••••'.mb_substr($oldPhone, -4),
            'to' => '••••'.mb_substr($newPhone, -4),
        ]);

        return ApiResponse::success(['phone' => $newPhone], __('api.phone_changed'));
    }
}
