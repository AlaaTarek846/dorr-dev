<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\CatalogController;
use App\Http\Requests\General\PrivacyPolicyRequest;
use App\Services\General\PrivacyPolicyService;

class PrivacyPolicyController extends CatalogController
{
    protected static function adminPermissionGroup(): string
    {
        return 'privacy-policy';
    }

    public function __construct(PrivacyPolicyService $service)
    {
        parent::__construct($service);
    }

    public function store(PrivacyPolicyRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function update(PrivacyPolicyRequest $request, int|string $privacy_policy)
    {
        return $this->service->updateRecord($privacy_policy, $request->validated());
    }

    public function deleteMultiple(PrivacyPolicyRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(PrivacyPolicyRequest $request, int|string $privacy_policy)
    {
        return $this->service->changeStatus($privacy_policy, (bool) $request->validated('status'));
    }
}
