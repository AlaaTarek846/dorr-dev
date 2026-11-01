<?php

namespace Modules\Freelance\Http\Controllers;

use App\Enums\LegalPageType;
use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\User\Services\MobileLegalPageService;

class FreelanceLegalPageController extends Controller
{
    public function __construct(
        protected MobileLegalPageService $service,
    ) {}

    /**
     * The privacy policy / terms of the Freelance service (public: the login screen shows them before sign-in).
     * A service without its own published page gets the general one, so the screen is never blank.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(LegalPageType::values())],
        ]);

        $serviceId = ServiceCategory::query()
            ->where('module_name', config('freelance.service_module_name', 'freelance'))
            ->value('id');

        return $this->service->page($validated['type'], $serviceId !== null ? (int) $serviceId : null, true);
    }
}
