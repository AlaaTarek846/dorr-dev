<?php

namespace Modules\Wallet\Repositories;

use App\Repositories\TranslatableRepository;
use Modules\Wallet\Models\WalletFeeRule;
use Modules\Wallet\Repositories\Concerns\SyncsNameAndDescription;

class WalletFeeRuleRepository extends TranslatableRepository
{
    use SyncsNameAndDescription;

    /**
     * @var list<string>
     */
    protected array $with = ['translations', 'translation', 'country', 'paymentMethod'];

    protected array $orderBy = ['priority' => 'desc', 'id' => 'desc'];

    public function __construct(WalletFeeRule $model)
    {
        $this->model = $model;
    }
}
