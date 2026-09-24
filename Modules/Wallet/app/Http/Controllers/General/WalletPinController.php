<?php

namespace Modules\Wallet\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Wallet\Exceptions\PinAlreadySetException;
use Modules\Wallet\Http\Requests\WalletPinRequest;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletNotifier;

/**
 * Wallet PIN endpoints, mounted under both mobile/user and provider groups.
 * The PIN is created lazily on first use: the app calls `show`, sees
 * has_pin=false, asks the user to enter + confirm a PIN, then calls `store`.
 */
class WalletPinController extends Controller
{
    public function __construct(
        private readonly PinService $pins,
        private readonly WalletNotifier $notifier,
    ) {}

    public function show(Request $request)
    {
        return ApiResponse::success(['has_pin' => $this->pins->has($request->user())], __('api.retrieved'));
    }

    public function store(WalletPinRequest $request)
    {
        $owner = $request->user();

        if ($this->pins->has($owner)) {
            throw new PinAlreadySetException;
        }

        $this->pins->set($owner, $request->validated('pin'));
        $this->notifier->pinCreated($owner);

        return ApiResponse::created(['has_pin' => true], __('api.created'));
    }

    /**
     * "Is this the right PIN?" — used to unlock the wallet screens. The check
     * itself (attempt counting, lockout) is RequiresWalletPin, which guards this
     * route; getting here *is* the proof, so there is nothing left to do.
     */
    public function verify()
    {
        return ApiResponse::success(['verified' => true], __('api.retrieved'));
    }

    /**
     * Changing needs the current PIN — verify() also counts the attempt and
     * applies the lockout, exactly like any other PIN-protected action.
     */
    public function update(WalletPinRequest $request)
    {
        $owner = $request->user();

        $this->pins->verify($owner, $request->validated('current_pin'));
        $this->pins->set($owner, $request->validated('pin'));
        $this->notifier->pinChanged($owner);

        return ApiResponse::success(['has_pin' => true], __('api.updated'));
    }
}
