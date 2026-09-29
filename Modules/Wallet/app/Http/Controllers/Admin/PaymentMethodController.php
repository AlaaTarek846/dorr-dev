<?php

namespace Modules\Wallet\Http\Controllers\Admin;

use App\Http\Controllers\CatalogController;
use Modules\Wallet\Http\Requests\PaymentMethodRequest;
use Modules\Wallet\Services\PaymentMethodService;

class PaymentMethodController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'payment-methods';
    }

    /**
     * Linking a method to countries is an edit of that method.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    protected static function extraAdminPermissionActionMethods(): array
    {
        return [['update', ['syncCountries']]];
    }

    public function __construct(PaymentMethodService $service)
    {
        parent::__construct($service);
    }

    public function store(PaymentMethodRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(PaymentMethodRequest $request, int|string $payment_method)
    {
        return $this->service->updateRecord($payment_method, $request->validated());
    }

    public function deleteMultiple(PaymentMethodRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(PaymentMethodRequest $request, int|string $payment_method)
    {
        return $this->service->changeStatus($payment_method, (bool) $request->validated('status'));
    }

    public function syncCountries(PaymentMethodRequest $request, int|string $payment_method)
    {
        /** @var PaymentMethodService $service */
        $service = $this->service;

        return $service->syncCountries($payment_method, $request->validated('countries'));
    }
}
