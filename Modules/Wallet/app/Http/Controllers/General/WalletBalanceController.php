<?php

namespace Modules\Wallet\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;
use Modules\Wallet\Support\OwnerType;
use Modules\Wallet\Support\WalletNumber;

/**
 * "How much do I have here?" — the wallet of the request's country (a wallet
 * is per owner + country, docs/wallet-plan.md §7). Wallets are created lazily,
 * and opening the wallet screen counts: the owner needs their wallet *number*
 * to be able to receive a transfer, so it exists from the first visit (with a
 * zero balance in the country's currency).
 */
class WalletBalanceController extends Controller
{
    public function __construct(private readonly WalletService $wallets) {}

    public function __invoke(Request $request)
    {
        $country = currentCountry();

        abort_if($country === null, 500, 'The country middleware did not run on this route.');

        $owner = $request->user();
        $wallet = $this->wallets->firstOrCreateWallet($owner, $country);

        $currency = $wallet->currency ?? $country->currency;
        $held = $wallet->held_withdrawable_minor + $wallet->held_spend_only_minor;

        return ApiResponse::success([
            'country_code' => $country->code,
            'dial_code' => $country->dial_code,
            // How a phone number looks in this country — the transfer form validates against it live.
            'phone_length' => $country->phone_length,
            'phone_starts_with' => $country->phone_starts_with,
            // The public number others can send to (this wallet only, not the owner's wallets elsewhere).
            'wallet_number' => $wallet->wallet_number,
            'wallet_number_formatted' => WalletNumber::format((string) $wallet->wallet_number),
            // What the wallet's QR code should encode (public facts only).
            'qr_payload' => WalletNumber::qrPayload($country->code, (string) $wallet->wallet_number),
            'currency_code' => $currency?->code,
            'currency_symbol' => $currency?->symbol,
            'total_minor' => $wallet->totalBalanceMinor(),
            'withdrawable_minor' => $wallet->withdrawable_minor,
            'spend_only_minor' => $wallet->spend_only_minor,
            'held_minor' => $held,
            // Read-only heads-up: balances in other countries can't be spent here.
            'other_wallets' => Wallet::query()
                ->with(['country:id,code', 'currency:id,code'])
                ->where('owner_type', OwnerType::aliasFor($owner))
                ->where('owner_id', $owner->getKey())
                ->where('country_id', '!=', $country->id)
                ->get()
                ->map(fn (Wallet $other) => [
                    'country_code' => $other->country?->code,
                    'currency_code' => $other->currency?->code,
                    'total_minor' => $other->totalBalanceMinor(),
                ])
                ->filter(fn (array $other) => $other['total_minor'] !== 0)
                ->values(),
        ], __('api.retrieved'));
    }
}
