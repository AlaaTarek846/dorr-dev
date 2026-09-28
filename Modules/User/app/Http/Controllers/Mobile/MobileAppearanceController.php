<?php

namespace Modules\User\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\User\Http\Requests\MobileAppearanceRequest;
use Modules\User\Models\User;
use Modules\User\Services\MobileAppearanceService;

class MobileAppearanceController extends Controller
{
    public function __construct(
        protected MobileAppearanceService $service,
    ) {}

    public function show(Request $request)
    {
        /** @var User $user */
        $user = $request->user('user_api');

        return $this->service->show($user);
    }

    public function update(MobileAppearanceRequest $request)
    {
        /** @var User $user */
        $user = $request->user('user_api');

        return $this->service->update($user, $request->validated());
    }
}
