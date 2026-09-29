<?php

namespace Modules\Wallet\Services;

use Modules\Wallet\Contracts\PaymentGateway;
use Modules\Wallet\Exceptions\PaymentGatewayException;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Services\Gateways\ArbGateway;
use Modules\Wallet\Services\Gateways\MyFatoorahGateway;
use Modules\Wallet\Services\Gateways\SandboxGateway;
use Modules\Wallet\Services\Gateways\UrPayGateway;

/**
 * Picks the driver from payment_methods.gateway — adding a gateway for a new
 * country means adding a class here and a config-driven driver key, never
 * touching an if/else in a controller (docs/wallet-structure.md §9's
 * "الطبقة الخلفية").
 */
class PaymentGatewayRegistry
{
    /**
     * @var array<string, class-string<PaymentGateway>>
     */
    private array $drivers = [
        'myfatoorah' => MyFatoorahGateway::class,
        'arb' => ArbGateway::class,
        'urpay' => UrPayGateway::class,
    ];

    public function for(PaymentMethod $paymentMethod): PaymentGateway
    {
        return $this->driver($paymentMethod->gateway);
    }

    public function driver(string $gatewayKey): PaymentGateway
    {
        $class = $this->drivers[$gatewayKey] ?? null;

        // The fake bank exists only where the config explicitly allows it.
        if ($gatewayKey === 'sandbox' && config('wallet.sandbox_enabled')) {
            $class = SandboxGateway::class;
        }

        if ($class === null) {
            throw new PaymentGatewayException("No PaymentGateway driver registered for gateway '{$gatewayKey}'.");
        }

        return app($class);
    }
}
