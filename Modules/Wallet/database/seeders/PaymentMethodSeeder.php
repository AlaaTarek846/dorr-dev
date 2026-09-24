<?php

namespace Modules\Wallet\Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Modules\Wallet\Enums\PaymentMethodType;
use Modules\Wallet\Models\PaymentMethod;

/**
 * Seeds the three gateways ported from LeeTaxi (MyFatoorah) and Jawad (ARB,
 * URPay) — docs/wallet-tasks.md Phase 5. All three exist as rows; which ones
 * can be *charged* depends on credentials: those in the .env
 * (config('wallet.gateway_seed_credentials')) are stored on first seeding, and the rest
 * are entered later from the admin dashboard. Until then a method is listed as "coming soon".
 *
 * All three are Saudi-market gateways (ARB = Al Rajhi Bank, URPay = SAR
 * wallets, MyFatoorah as used by LeeTaxi with +966), so they're linked to
 * Saudi Arabia (code SA) when that country exists — not to whichever country
 * happens to be is_default.
 */
class PaymentMethodSeeder extends Seeder
{
    private const LINKED_COUNTRY_CODE = 'SA';

    public function run(): void
    {
        $saudi = Country::query()->where('code', self::LINKED_COUNTRY_CODE)->first();

        foreach ($this->definitions() as $sortOrder => $data) {
            $credentials = $this->credentialsFor($data['gateway']);

            // Re-running the seeder must never undo what the admin did in the dashboard: an existing
            // method keeps its status/sort order, and credentials are only written when the .env has some.
            $method = PaymentMethod::query()->firstOrNew(['code' => $data['code']]);

            if (! $method->exists) {
                $method->fill([
                    'gateway' => $data['gateway'],
                    'type' => PaymentMethodType::Online,
                    'is_global' => false,
                    'supports_topup' => true,
                    // Listed straight away: a gateway without credentials shows as "coming soon" in the app
                    // (PaymentMethod::isConfigured()) and can't be charged until the admin enters them.
                    'status' => true,
                    'sort_order' => $sortOrder,
                ]);
            }

            if ($credentials !== null) {
                $method->credentials = $credentials;
            }

            $method->save();

            $this->syncPaymentMethodTranslations($method, $data['translations']);

            if ($saudi !== null) {
                $method->countryLinks()->firstOrCreate(['country_id' => $saudi->id], ['status' => true]);
            }
        }

        $this->seedSandbox();
    }

    /**
     * Local/testing only: a global, always-live fake gateway so top-ups can be
     * demoed end to end (Services\Gateways\SandboxGateway). Never seeded where
     * `wallet.sandbox_enabled` is off, i.e. production.
     */
    private function seedSandbox(): void
    {
        if (! config('wallet.sandbox_enabled')) {
            return;
        }

        $method = PaymentMethod::query()->updateOrCreate(
            ['code' => 'sandbox_card'],
            [
                'gateway' => 'sandbox',
                'type' => PaymentMethodType::Online,
                'is_global' => true,
                'supports_topup' => true,
                'status' => true,
                'sort_order' => 100,
                'credentials' => null,
            ],
        );

        $this->syncPaymentMethodTranslations($method, [
            'en' => ['name' => 'Test payment (sandbox)', 'description' => 'A fake bank for testing — no real money moves.'],
            'ar' => ['name' => 'دفع تجريبي (Sandbox)', 'description' => 'بنك وهمي للتجربة — لا يتم خصم أي مبلغ حقيقي.'],
        ]);
    }

    /**
     * @return array<string, mixed>|null null when any required key is missing/empty
     */
    private function credentialsFor(string $gateway): ?array
    {
        $credentials = config("wallet.gateway_seed_credentials.{$gateway}", []);

        // URPay's `mode` and `test_consumer_mobile_number` are optional.
        $optional = ['mode', 'test_consumer_mobile_number'];

        foreach ($credentials as $key => $value) {
            if (in_array($key, $optional, true)) {
                continue;
            }

            if ($value === null || $value === '') {
                return null;
            }
        }

        return $credentials === [] ? null : $credentials;
    }

    /**
     * @param  array<string, array{name: string, description: string}>  $translations
     */
    private function syncPaymentMethodTranslations(PaymentMethod $method, array $translations): void
    {
        foreach ($translations as $locale => $fields) {
            $method->translations()->updateOrCreate(['locale' => $locale], $fields);
        }
    }

    /**
     * @return list<array{code: string, gateway: string, translations: array<string, array{name: string, description: string}>}>
     */
    private function definitions(): array
    {
        return [
            [
                'code' => 'myfatoorah_card',
                'gateway' => 'myfatoorah',
                'translations' => [
                    'en' => ['name' => 'Card / Mada (MyFatoorah)', 'description' => 'Pay by card or Mada through MyFatoorah.'],
                    'ar' => ['name' => 'بطاقة / مدى (ماي فاتورة)', 'description' => 'الدفع بالبطاقة أو مدى عبر ماي فاتورة.'],
                ],
            ],
            [
                'code' => 'arb_card',
                'gateway' => 'arb',
                'translations' => [
                    'en' => ['name' => 'Al Rajhi Bank Card', 'description' => 'Pay by card through Al Rajhi Bank.'],
                    'ar' => ['name' => 'بطاقة بنك الراجحي', 'description' => 'الدفع بالبطاقة عبر بنك الراجحي.'],
                ],
            ],
            [
                'code' => 'urpay_wallet',
                'gateway' => 'urpay',
                'translations' => [
                    'en' => ['name' => 'URPay Wallet', 'description' => 'Pay from your URPay mobile wallet using an OTP.'],
                    'ar' => ['name' => 'محفظة يو آر باي', 'description' => 'الدفع من محفظة يو آر باي عبر رمز التحقق.'],
                ],
            ],
        ];
    }
}
