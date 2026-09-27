<?php

namespace Modules\Wallet\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Wallet\Enums\RecoveryMethod;
use Modules\Wallet\Exceptions\PinRequiredException;
use Modules\Wallet\Exceptions\RecoveryException;
use Modules\Wallet\Http\Requests\WalletRecoveryRequest;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletRecoveryService;

/**
 * Wallet-PIN recovery, for users and providers alike (mounted under both route groups):
 *
 *  - choosing the recovery method (required before a PIN can be created; changing it later needs the PIN),
 *  - confirming an e-mail with its 4-digit code,
 *  - "I forgot my PIN": recover with the method on file — no PIN needed, that is the point.
 */
class WalletRecoveryController extends Controller
{
    public function __construct(
        private readonly WalletRecoveryService $recovery,
        private readonly PinService $pins,
    ) {}

    public function setup(WalletRecoveryRequest $request)
    {
        $owner = $request->user();

        // Replacing an existing recovery method is a sensitive change: prove you know the PIN first.
        if ($this->pins->has($owner)) {
            $pin = $request->header('X-Wallet-Pin');

            if (! is_string($pin) || $pin === '') {
                throw new PinRequiredException;
            }

            $this->pins->verify($owner, $pin);
        }

        $this->recovery->configure(
            $owner,
            RecoveryMethod::from($request->validated('method')),
            $request->validated(),
            $request->file('document'),
        );

        return ApiResponse::success($this->recovery->summary($owner), __('api.updated'));
    }

    /** Sends (or re-sends) the 4-digit code to the e-mail on file — to confirm it, or to recover. */
    public function sendEmailCode(Request $request)
    {
        $this->recovery->resendEmailCode($request->user(), $request->boolean('pending'));

        return ApiResponse::success(['sent' => true], __('api.updated'));
    }

    public function confirmEmail(WalletRecoveryRequest $request)
    {
        $this->recovery->confirmEmail($request->user(), $request->validated('code'));

        return ApiResponse::success($this->recovery->summary($request->user()), __('api.updated'));
    }

    /**
     * "I forgot my PIN".
     *  - password / birth date / e-mail code + a new PIN → the PIN is replaced right away;
     *  - a document photo → a request for the dashboard to review (the PIN only changes if approved).
     */
    public function recover(WalletRecoveryRequest $request)
    {
        $owner = $request->user();
        $method = $this->recovery->methodFor($owner)?->method ?? throw RecoveryException::notConfigured();

        if ($method->isDocument()) {
            $file = $request->file('document');

            if ($file === null) {
                throw RecoveryException::wrongMethod($method->value);
            }

            $this->recovery->requestWithDocument($owner, $file);

            return ApiResponse::created($this->recovery->summary($owner), __('api.created'));
        }

        // Every other method replaces the PIN on the spot, so a confirmed new PIN is part of the request.
        $request->validate(['pin' => ['required', 'digits:4', 'confirmed']]);

        $this->recovery->recover($owner, $request->safe()->only(['password', 'birth_date', 'code']), (string) $request->input('pin'));

        return ApiResponse::success($this->recovery->summary($owner), __('api.updated'));
    }
}
