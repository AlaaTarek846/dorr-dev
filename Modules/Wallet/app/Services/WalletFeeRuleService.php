<?php

namespace Modules\Wallet\Services;

use App\Services\CatalogService;
use Modules\Wallet\Http\Resources\WalletFeeRuleResource;
use Modules\Wallet\Models\WalletFeeRule;
use Modules\Wallet\Repositories\WalletFeeRuleRepository;

class WalletFeeRuleService extends CatalogService
{
    protected ?string $resource = WalletFeeRuleResource::class;

    public function __construct(WalletFeeRuleRepository $repository)
    {
        parent::__construct($repository);
    }

    protected function beforeStore(array $data): array
    {
        // Only top-up rules exist for now; the column is there so transfer /
        // withdrawal fees can reuse the table later without a migration.
        $data['operation'] = WalletFeeRule::OPERATION_TOPUP;
        $data['created_by'] = auth('admin_api')->id();
        $data['updated_by'] = auth('admin_api')->id();

        return $data;
    }

    protected function beforeUpdate(int|string $id, array $data): array
    {
        $data['updated_by'] = auth('admin_api')->id();

        return $data;
    }
}
