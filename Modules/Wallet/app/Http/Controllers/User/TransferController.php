<?php

namespace Modules\Wallet\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Exceptions\TransferException;
use Modules\Wallet\Http\Requests\TransferRequest;
use Modules\Wallet\Http\Resources\WalletTransactionResource;
use Modules\Wallet\Models\WalletSetting;
use Modules\Wallet\Services\TransferRecipientResolver;
use Modules\Wallet\Services\TransferService;

/**
 * User → user transfer, in two steps (mobile/user only):
 *
 *  1. `lookup` (no PIN — it moves nothing) — who is behind this phone/wallet
 *     number? Returns the masked name to confirm and a short-lived token.
 *  2. `store` (behind RequiresWalletPin) — pays the recipient that token names.
 *
 * The response of `store` is the *sender's* ledger row — the recipient's side
 * is never returned to the sender.
 */
class TransferController extends Controller
{
    public function __construct(
        private readonly TransferService $transfers,
        private readonly TransferRecipientResolver $recipients,
    ) {}

    public function lookup(TransferRequest $request)
    {
        $country = $this->country();
        $sender = $request->user();

        // Tell the sender up front if transfers are off here, not after they typed everything.
        $settings = WalletSetting::query()->where('country_id', $country->id)->first() ?? throw TransferException::settingsMissing();

        if (! $settings->transfers_enabled) {
            throw TransferException::disabled();
        }

        $data = match ($request->validated('mode')) {
            'phone' => $this->recipients->byPhone($sender, $country, (string) $request->validated('phone')),
            'qr' => $this->recipients->byQr($sender, $country, (string) $request->validated('qr')),
            default => $this->recipients->byWalletNumber($sender, $country, (string) $request->validated('wallet_number')),
        };

        return ApiResponse::success($data, __('api.retrieved'));
    }

    public function store(TransferRequest $request)
    {
        $result = $this->transfers->send(
            $request->user(),
            $this->country(),
            $request->validated('recipient_token'),
            (int) $request->validated('amount_minor'),
            $request->validated('from_bucket') ? WalletBucket::from($request->validated('from_bucket')) : null,
            $request->validated('idempotency_key'),
        );

        return ApiResponse::created(new WalletTransactionResource($result['out']->load('counterpartyWallet')), __('api.created'));
    }

    private function country(): Country
    {
        $country = currentCountry();

        abort_if($country === null, 500, 'The country middleware did not run on this route.');

        return $country;
    }
}
