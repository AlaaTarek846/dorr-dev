<?php

namespace Modules\Chat\Services;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Exceptions\ChatException;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Support\MaskedName;
use Modules\Wallet\Support\OwnerType;
use Modules\Wallet\Support\WalletNumber;

/**
 * The two chat cards that come from the Wallet module (docs/chat-plan.md §10.0.1).
 *
 * The app sends only an id; the card itself is always built here, from the sender's *own*
 * wallet — so nobody can fake a receipt, or share someone else's transfer or wallet. The card
 * is a snapshot: it stays exactly as sent even if something changes later. Neither card moves
 * money: "pay" on a QR card opens the normal transfer confirmation (wallet/transfers/lookup).
 */
class WalletShareService
{
    /**
     * @return array<string, mixed>
     *
     * @throws ChatException
     */
    public function transferReceipt(Model $sender, string $transactionUuid): array
    {
        $transaction = WalletTransaction::query()
            ->where('uuid', $transactionUuid)
            ->where('type', WalletTransactionType::TransferOut->value)
            ->whereHas('wallet', fn ($q) => $q->where('owner_type', OwnerType::aliasFor($sender))->where('owner_id', $sender->getKey()))
            ->with(['wallet.currency', 'counterpartyWallet'])
            ->first() ?? throw ChatException::walletTransferNotFound();

        $currency = $transaction->wallet->currency;
        $recipient = $transaction->counterpartyWallet?->owner();

        return [
            'transaction_id' => $transaction->uuid,
            'amount_minor' => $transaction->amount_minor,
            'currency' => $currency?->code,
            'currency_symbol' => $currency?->symbol,
            'decimal_places' => $currency?->decimal_places ?? 2,
            'recipient_name' => MaskedName::of($recipient?->name),
            'recipient_wallet_number' => $transaction->counterpartyWallet?->wallet_number
                ? WalletNumber::format($transaction->counterpartyWallet->wallet_number)
                : null,
            'sender_name' => MaskedName::of($sender->name ?? null),
            'is_reversed' => $transaction->isReversed(),
            'transferred_at' => $transaction->created_at?->toIso8601String(),
        ];
    }

    /**
     * My wallet's QR, in the given country (default: the request's country).
     *
     * @return array<string, mixed>
     *
     * @throws ChatException
     */
    public function walletQr(Model $owner, ?string $countryCode): array
    {
        $country = $countryCode
            ? Country::query()->where('code', strtoupper($countryCode))->first()
            : currentCountry();

        $wallet = $country === null ? null : Wallet::query()
            ->where('owner_type', OwnerType::aliasFor($owner))->where('owner_id', $owner->getKey())
            ->where('country_id', $country->id)
            ->with('currency')->first();

        if ($wallet === null || $wallet->wallet_number === null) {
            throw ChatException::walletNotFound();
        }

        return [
            'qr_payload' => WalletNumber::qrPayload((string) $country->code, $wallet->wallet_number),
            'wallet_number' => WalletNumber::format($wallet->wallet_number),
            'country_code' => $country->code,
            'currency' => $wallet->currency?->code,
            'owner_name' => MaskedName::of($owner->name ?? null),
        ];
    }
}
