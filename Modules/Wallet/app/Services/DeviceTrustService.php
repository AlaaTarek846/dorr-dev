<?php

namespace Modules\Wallet\Services;

use App\Enums\VerificationType;
use App\Services\Auth\VerificationCodeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Wallet\Exceptions\DeviceIdRequiredException;
use Modules\Wallet\Models\WalletTrustedDevice;
use Modules\Wallet\Support\OwnerType;

/**
 * "Has this device opened this wallet before?" (wallet policy bend 3). `device_id` is whatever the app
 * sends in `X-Device-Id` — a random id it generates once and keeps locally, not a hardware identifier;
 * the most a client-supplied value can honestly promise is "the same app install asked before".
 *
 * A device that has never been seen still opens the wallet (the PIN already proved that), but has to
 * additionally prove the phone on file is reachable from it — {@see self::sendCode()} /
 * {@see self::confirm()} — before it counts as trusted. No SMS gateway is wired yet, so the code follows
 * the same dev-fixed-code convention as everything else (VerificationCodeService::send()).
 */
class DeviceTrustService
{
    public function __construct(private readonly VerificationCodeService $codes) {}

    public function isTrusted(Model $owner, ?string $deviceId): bool
    {
        if ($deviceId === null || $deviceId === '') {
            return false;
        }

        return WalletTrustedDevice::query()
            ->where('owner_type', OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey())
            ->where('device_id', $deviceId)
            ->exists();
    }

    public function sendCode(Model $owner): void
    {
        $this->codes->send($owner, VerificationType::DeviceTrust, (string) $owner->phone);
    }

    /**
     * @throws DeviceIdRequiredException  no X-Device-Id was sent — nothing to trust
     * @throws ValidationException        wrong / expired / too many tries
     */
    public function confirm(Model $owner, string $code, ?string $deviceId): void
    {
        if ($deviceId === null || $deviceId === '') {
            throw new DeviceIdRequiredException;
        }

        $this->codes->verify($owner, VerificationType::DeviceTrust, $code);

        WalletTrustedDevice::query()->updateOrCreate(
            [
                'owner_type' => OwnerType::aliasFor($owner),
                'owner_id' => $owner->getKey(),
                'device_id' => $deviceId,
            ],
            ['trusted_at' => now(), 'last_seen_at' => now()],
        );
    }

    /** Bumps `last_seen_at` for an already-trusted device — best-effort, called from pin/verify. */
    public function touch(Model $owner, ?string $deviceId): void
    {
        if ($deviceId === null || $deviceId === '') {
            return;
        }

        WalletTrustedDevice::query()
            ->where('owner_type', OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey())
            ->where('device_id', $deviceId)
            ->update(['last_seen_at' => now()]);
    }
}
