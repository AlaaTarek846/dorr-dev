<?php

namespace Modules\Wallet\Repositories;

use App\Repositories\BaseRepository;
use Modules\Wallet\Models\WalletSetting;

class WalletSettingRepository extends BaseRepository
{
    /**
     * @var list<string>
     */
    protected array $with = ['country'];

    public function __construct(WalletSetting $model)
    {
        $this->model = $model;
    }

    public function forCountry(int $countryId): ?WalletSetting
    {
        return $this->model->newQuery()
            ->where('country_id', $countryId)
            ->first();
    }
}
