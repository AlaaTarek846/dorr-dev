<?php

namespace App\Repositories\General;

use App\Models\Referral;
use App\Repositories\BaseRepository;

class ReferralRepository extends BaseRepository
{
    protected array $with = [
        'referralCode',
    ];

    protected array $orderBy = [
        'id' => 'desc',
    ];

    public function __construct(Referral $model)
    {
        $this->model = $model;
    }
}
