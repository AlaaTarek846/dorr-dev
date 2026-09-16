<?php

namespace Modules\Provider\Repositories;

use App\Repositories\BaseRepository;
use Modules\Admin\Models\Admin;
use Modules\Provider\Enums\ProviderServiceStatus;
use Modules\Provider\Enums\ProviderStatus;
use Modules\Provider\Models\ProviderProfile;
use Modules\Provider\Models\ProviderService;

class ProviderProfileRepository extends BaseRepository
{
    protected array $with = ['user.country', 'approver', 'services.category'];

    protected array $orderBy = [
        'id' => 'desc',
    ];

    public function __construct(ProviderProfile $model)
    {
        $this->model = $model;
    }

    public function approve(ProviderProfile $profile, Admin $admin): ProviderProfile
    {
        $profile->update([
            'status' => ProviderStatus::Approved,
            'rejection_reason' => null,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        return $this->refresh($profile);
    }

    public function reject(ProviderProfile $profile, string $reason): ProviderProfile
    {
        $profile->update([
            'status' => ProviderStatus::Rejected,
            'rejection_reason' => $reason,
        ]);

        return $this->refresh($profile);
    }

    public function suspend(ProviderProfile $profile): ProviderProfile
    {
        $profile->update(['status' => ProviderStatus::Suspended]);

        return $this->refresh($profile);
    }

    public function reactivate(ProviderProfile $profile): ProviderProfile
    {
        $profile->update(['status' => ProviderStatus::Approved]);

        return $this->refresh($profile);
    }

    public function addService(ProviderProfile $profile, int $categoryId): ProviderService
    {
        $service = $profile->services()->create([
            'service_category_id' => $categoryId,
            'status' => ProviderServiceStatus::Pending,
        ]);

        $service->load('category');

        return $service;
    }

    public function changeServiceStatus(ProviderService $service, ProviderServiceStatus $status): ProviderService
    {
        $service->update(['status' => $status]);
        $service->load('category');

        return $service;
    }
}
