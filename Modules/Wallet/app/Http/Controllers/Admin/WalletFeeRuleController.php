<?php

namespace Modules\Wallet\Http\Controllers\Admin;

use App\Http\Controllers\CatalogController;
use Modules\Wallet\Http\Requests\WalletFeeRuleRequest;
use Modules\Wallet\Services\WalletFeeRuleService;

class WalletFeeRuleController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'wallet-fee-rules';
    }

    public function __construct(WalletFeeRuleService $service)
    {
        parent::__construct($service);
    }

    public function store(WalletFeeRuleRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(WalletFeeRuleRequest $request, int|string $wallet_fee_rule)
    {
        return $this->service->updateRecord($wallet_fee_rule, $request->validated());
    }

    public function deleteMultiple(WalletFeeRuleRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(WalletFeeRuleRequest $request, int|string $wallet_fee_rule)
    {
        return $this->service->changeStatus($wallet_fee_rule, (bool) $request->validated('status'));
    }
}
