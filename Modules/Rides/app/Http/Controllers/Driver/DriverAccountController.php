<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Models\DriverPreference;
use Modules\Rides\Services\Drivers\DriverAccount;
use Modules\Wallet\Models\WalletTrustedDevice;

/**
 * The captain's settings and account (D-304, D-313, D-314, D-316..D-318): alerts (sound, vibration, voice), the
 * devices trusted with the wallet, a new number, the terms in force and accepting them again, and closing.
 */
class DriverAccountController extends Controller
{
    public function __construct(private readonly DriverAccount $account) {}

    /** D-304: how a request rings. */
    public function alerts(Request $request): JsonResponse
    {
        $pref = DriverPreference::query()->find($this->driver($request)->id);
        $sounds = (array) ($pref?->sounds ?? []);

        return ApiResponse::success(['sound' => (bool) ($sounds['sound'] ?? true), 'vibration' => (bool) ($sounds['vibration'] ?? true), 'voice' => (bool) ($pref?->voice_readout ?? false)], __('api.retrieved'));
    }

    public function saveAlerts(Request $request): JsonResponse
    {
        $data = $request->validate(['sound' => ['sometimes', 'boolean'], 'vibration' => ['sometimes', 'boolean'], 'voice' => ['sometimes', 'boolean']]);
        $pref = DriverPreference::query()->firstOrNew(['driver_id' => $this->driver($request)->id]);
        $sounds = (array) ($pref->sounds ?? []);
        foreach (['sound', 'vibration'] as $k) {
            if (array_key_exists($k, $data)) {
                $sounds[$k] = (bool) $data[$k];
            }
        }
        $pref->sounds = $sounds;
        if (array_key_exists('voice', $data)) {
            $pref->voice_readout = (bool) $data['voice'];
        }
        $pref->save();

        return $this->alerts($request);
    }

    /** D-313: the phones trusted with the wallet; this one is marked. */
    public function devices(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $current = (string) $request->header('X-Device-Id', '');

        return ApiResponse::success(WalletTrustedDevice::query()->where('owner_type', 'driver')->where('owner_id', $driver->id)->latest('last_seen_at')->get()
            ->map(fn (WalletTrustedDevice $d) => [
                'id' => $d->id, 'device' => substr((string) $d->device_id, 0, 8).'…', 'current' => $current !== '' && hash_equals((string) $d->device_id, $current),
                'trusted_at' => $d->trusted_at?->toIso8601String(), 'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            ])->values(), __('api.retrieved'));
    }

    public function forgetDevice(Request $request, int $id): JsonResponse
    {
        WalletTrustedDevice::query()->where('owner_type', 'driver')->where('owner_id', $this->driver($request)->id)->whereKey($id)->delete();

        return $this->devices($request);
    }

    /** D-314: a new number — the PIN when there is one, then a code to the new number. */
    public function phone(Request $request): JsonResponse
    {
        $data = $request->validate(['dial_code' => ['sometimes', 'string', 'max:6'], 'phone' => ['required', 'string', 'regex:/^\d{6,15}$/']]);
        $driver = $this->driver($request);
        // Without a dial code the number stays in the captain's own country.
        $dial = $data['dial_code'] ?? (string) \App\Models\Country::query()->whereKey($driver->country_id)->value('dial_code');
        $this->account->startPhoneChange($driver, '+'.ltrim($dial, '+').ltrim($data['phone'], '0'), $request->header('X-Wallet-Pin'));

        return ApiResponse::success(['sent' => true], __('rides.account.code_sent'));
    }

    public function confirmPhone(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $driver = $this->account->confirmPhoneChange($this->driver($request), $data['code']);

        return ApiResponse::success(['phone' => $driver->phone], __('rides.account.phone_changed'));
    }

    /** D-316, D-226: what blocks closing, then closing. */
    public function closeCheck(Request $request): JsonResponse
    {
        $blockers = $this->account->closeBlockers($this->driver($request));

        return ApiResponse::success(['can_close' => $blockers === [], 'blockers' => $blockers], __('api.retrieved'));
    }

    public function close(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300'], 'confirm' => ['required', 'accepted']]);
        $this->account->close($this->driver($request), $data['reason']);

        return ApiResponse::success(['closed' => true], __('rides.account.closed'));
    }

    /** D-317, D-318: the terms in force, and accepting them. */
    public function legal(Request $request): JsonResponse
    {
        return ApiResponse::success($this->account->legal($this->driver($request)), __('api.retrieved'));
    }

    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate(['types' => ['required', 'array'], 'types.*' => ['in:term,privacy']]);
        $this->account->accept($this->driver($request), $data['types']);

        return ApiResponse::success($this->account->legal($this->driver($request)), __('api.updated'));
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
