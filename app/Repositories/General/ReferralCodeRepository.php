<?php

namespace App\Repositories\General;

use App\Models\ReferralCode;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Model;

class ReferralCodeRepository extends BaseRepository
{
    protected array $withCount = [
        'referrals',
    ];

    protected array $orderBy = [
        'id' => 'desc',
    ];

    public function __construct(ReferralCode $model)
    {
        $this->model = $model;
    }

    public function show(int|string $id): Model
    {
        return $this->applyShowDefaults($this->query()->with('referrals.referralCode'))
            ->findOrFail($id);
    }

    public function changeStatus(int|string $id, bool $status): Model
    {
        $model = $this->query()->findOrFail($id);
        $model->update(['is_active' => $status]);

        return $this->refresh($model);
    }
}
