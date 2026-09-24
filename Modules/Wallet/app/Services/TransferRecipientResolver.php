<?php

namespace Modules\Wallet\Services;

use App\Enums\UserStatus;
use App\Models\Country;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\User;
use Modules\Wallet\Exceptions\TransferException;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Support\MaskedName;
use Modules\Wallet\Support\WalletNumber;

/**
 * "Who exactly am I about to pay?" — step one of every transfer, done *before*
 * any money moves. A recipient is found either by phone number or by wallet
 * number, always inside the sender's own wallet country (a Saudi wallet only
 * ever pays Saudi wallets), and the answer is a short-lived signed token that
 * the transfer itself must present. So what the sender confirmed on screen is
 * provably what gets paid, and nobody can skip the confirmation step.
 *
 * Why a wallet number exists at all: a person can hold a wallet in every
 * country. A phone number can't say *which* of them should receive the money;
 * a wallet number can.
 */
class TransferRecipientResolver
{
    private const TOKEN_TTL_SECONDS = 600;

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException  malformed phone (country rules) — a field error
     * @throws TransferException    nobody payable behind these details
     */
    public function byPhone(User $sender, Country $country, string $rawPhone): array
    {
        $national = $this->nationalPhone($country, $rawPhone);
        $fullPhone = '+'.ltrim((string) $country->dial_code, '+').$national;

        // Yourself is the one "not found" the sender is allowed to be told apart — it's their own number.
        if ($sender->phone === $fullPhone) {
            throw TransferException::toSelf();
        }

        // The country is decided by the number itself (its dial code), not by users.country_id:
        // that column is only filled in once a profile is completed, so it can't be relied on.
        $recipient = User::query()
            ->where('phone', $fullPhone)
            ->where('status', UserStatus::Active)
            ->first();

        $this->assertPayable($sender, $recipient);

        // The wallet may not exist yet (it is created on first receipt).
        $wallet = $this->walletOf($recipient, $country);

        return $this->present($sender, $country, $recipient, $wallet, 'phone', ['phone' => $fullPhone]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     * @throws TransferException
     */
    public function byWalletNumber(User $sender, Country $country, string $rawNumber): array
    {
        $number = WalletNumber::normalize($rawNumber);

        if ($number === null || ! WalletNumber::isValid($number)) {
            throw ValidationException::withMessages(['wallet_number' => [__('wallet.errors.transfer_wallet_number_invalid')]]);
        }

        $wallet = Wallet::query()
            ->where('wallet_number', $number)
            ->where('owner_type', 'user')
            ->where('country_id', $country->id) // a wallet of another country is simply not payable from here
            ->where('status', true)
            ->first();

        if ($wallet !== null && $wallet->owner_id === $sender->id) {
            throw TransferException::toSelf();
        }

        $recipient = $wallet === null
            ? null
            : User::query()->where('id', $wallet->owner_id)->where('status', UserStatus::Active)->first();

        $this->assertPayable($sender, $recipient);

        return $this->present($sender, $country, $recipient, $wallet, 'wallet', []);
    }

    /**
     * Someone scanned a wallet QR code. Same as typing its wallet number — except the code also names a
     * country, so a QR of another country's wallet gets a precise message instead of "not found".
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     * @throws TransferException
     */
    public function byQr(User $sender, Country $country, string $payload): array
    {
        $parsed = WalletNumber::parseQr($payload);

        if ($parsed === null || ! WalletNumber::isValid($parsed['number'])) {
            throw ValidationException::withMessages(['qr' => [__('wallet.errors.transfer_qr_invalid')]]);
        }

        if ($parsed['country'] !== strtoupper((string) $country->code)) {
            throw TransferException::qrOtherCountry($parsed['country'], (string) $country->code);
        }

        return $this->byWalletNumber($sender, $country, $parsed['number']);
    }

    /**
     * Validates the token from a previous lookup and returns who it points at.
     *
     * @return array{user: User, wallet: Wallet|null}
     *
     * @throws TransferException
     */
    public function resolveToken(User $sender, Country $country, string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw TransferException::recipientExpired();
        }

        if (($payload['sender'] ?? null) !== $sender->id
            || ($payload['country_id'] ?? null) !== $country->id
            || ($payload['exp'] ?? 0) < now()->timestamp) {
            throw TransferException::recipientExpired();
        }

        $recipient = User::query()->where('id', $payload['recipient'])->where('status', UserStatus::Active)->first();

        $this->assertPayable($sender, $recipient);

        $wallet = isset($payload['wallet_id'])
            ? Wallet::query()->where('id', $payload['wallet_id'])->where('owner_type', 'user')->where('owner_id', $recipient->id)->where('country_id', $country->id)->first()
            : null;

        if (isset($payload['wallet_id']) && $wallet === null) {
            throw TransferException::recipientUnavailable();
        }

        return ['user' => $recipient, 'wallet' => $wallet];
    }

    /**
     * What a person typed → the national number this country expects, or a
     * field error saying what the format is. Accepts Arabic-Indic digits, spaces,
     * dashes, and a leading 0 or the dial code ("05…", "+9665…").
     *
     * @throws ValidationException
     */
    public function nationalPhone(Country $country, string $raw): string
    {
        $digits = WalletNumber::normalize($raw) ?? '';
        $dial = ltrim((string) $country->dial_code, '+');
        $length = $country->phone_length !== null ? (int) $country->phone_length : null;

        if ($length !== null && $dial !== '' && strlen($digits) === strlen($dial) + $length && str_starts_with($digits, $dial)) {
            $digits = substr($digits, strlen($dial));
        }

        if ($length !== null && strlen($digits) === $length + 1 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        $prefix = (string) $country->phone_starts_with;

        if (($length !== null && strlen($digits) !== $length) || ($prefix !== '' && ! str_starts_with($digits, $prefix))) {
            throw ValidationException::withMessages(['phone' => [__('wallet.errors.transfer_phone_invalid', [
                'country' => $country->code,
                'length' => $length ?? '-',
                'prefix' => $prefix !== '' ? $prefix : '-',
            ])]]);
        }

        return $digits;
    }

    private function assertPayable(User $sender, ?User $recipient): void
    {
        // One answer for "no such user", "inactive", "yourself", "other country".
        if ($recipient === null || $recipient->id === $sender->id) {
            throw TransferException::recipientUnavailable();
        }
    }

    private function walletOf(User $user, Country $country): ?Wallet
    {
        return Wallet::query()
            ->where('owner_type', 'user')->where('owner_id', $user->id)->where('country_id', $country->id)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function present(User $sender, Country $country, User $recipient, ?Wallet $wallet, string $via, array $extra): array
    {
        $token = Crypt::encryptString(json_encode([
            'sender' => $sender->id,
            'country_id' => $country->id,
            'recipient' => $recipient->id,
            // Pinned only when the sender addressed a specific wallet by its number.
            'wallet_id' => $via === 'wallet' ? $wallet?->id : null,
            'exp' => now()->timestamp + self::TOKEN_TTL_SECONDS,
        ], JSON_THROW_ON_ERROR));

        return [
            'recipient_token' => $token,
            'expires_in' => self::TOKEN_TTL_SECONDS,
            'via' => $via,
            'name_masked' => MaskedName::of($recipient->name),
            // Echoed only when the sender typed it; a phone lookup must not reveal the recipient's wallet number.
            'wallet_number' => $via === 'wallet' ? $wallet?->wallet_number : null,
            'country_code' => $country->code,
            'currency_code' => $country->currency?->code,
        ] + $extra;
    }
}
