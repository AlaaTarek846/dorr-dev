<?php

namespace Modules\Wallet\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Validation\Rule;
use Modules\Wallet\Exceptions\WithdrawalException;
use Modules\Wallet\Models\WalletSetting;

/**
 * Per-country wallet limits: withdrawal min/max, transfer caps, and the debt
 * limits behind WalletEligibilityService. One row per country, created
 * automatically when a country is (WalletSettingObserver) — so this screen
 * only ever edits, it never creates.
 */
class WalletSettingController extends Controller implements HasMiddleware
{
    /**
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('wallet-settings', [
            ['view', ['index', 'show']],
            ['update', ['update']],
        ]);
    }

    public function index()
    {
        return ApiResponse::success(
            WalletSetting::query()->with('country:id,code')->orderBy('country_id')->get()->map(fn (WalletSetting $s) => $this->present($s)),
            __('api.retrieved'),
        );
    }

    public function show(int $country)
    {
        return ApiResponse::success($this->present($this->findFor($country)), __('api.retrieved'));
    }

    public function update(Request $request, int $country)
    {
        $data = $request->validate([
            'min_topup_minor' => ['nullable', 'integer', 'min:0'],
            'max_topup_minor' => ['nullable', 'integer', 'min:0', Rule::when($request->filled('min_topup_minor'), ['gte:min_topup_minor'])],
            'min_withdrawal_minor' => ['nullable', 'integer', 'min:0'],
            'max_withdrawal_minor' => ['nullable', 'integer', 'min:0', Rule::when($request->filled('min_withdrawal_minor'), ['gte:min_withdrawal_minor'])],
            'transfer_max_per_transaction_minor' => ['nullable', 'integer', 'min:0'],
            'transfer_max_per_day_minor' => ['nullable', 'integer', 'min:0'],
            'transfer_max_per_month_minor' => ['nullable', 'integer', 'min:0'],
            'transfers_enabled' => ['nullable', 'boolean'],
            // Signed debt limits: 0 = no debt allowed, -10000 = up to 100.00 of debt. Never positive.
            'min_allowed_balance_provider_minor' => ['nullable', 'integer', 'max:0'],
            'min_allowed_balance_user_minor' => ['nullable', 'integer', 'max:0'],
            'status' => ['nullable', 'boolean'],
        ]);

        $setting = $this->findFor($country);
        $setting->update($data);

        return ApiResponse::success($this->present($setting->refresh()->load('country:id,code')), __('api.updated'));
    }

    private function findFor(int $countryId): WalletSetting
    {
        Country::query()->findOrFail($countryId);

        return WalletSetting::query()->with('country:id,code')->where('country_id', $countryId)->first()
            ?? throw WithdrawalException::settingsMissing();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WalletSetting $setting): array
    {
        return ['country_code' => $setting->country?->code] + $setting->only([
            'country_id', 'min_topup_minor', 'max_topup_minor', 'min_withdrawal_minor', 'max_withdrawal_minor',
            'transfer_max_per_transaction_minor', 'transfer_max_per_day_minor', 'transfer_max_per_month_minor',
            'transfers_enabled', 'min_allowed_balance_provider_minor', 'min_allowed_balance_user_minor', 'status',
        ]);
    }
}
