<?php

namespace Modules\User\Http\Controllers\Mobile;

use App\Enums\LegalPageType;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\User\Services\MobileLegalPageService;

class LegalPageController extends Controller
{
    public function __construct(
        protected MobileLegalPageService $service,
    ) {}

    /**
     * The active legal page (privacy/term) for a service, or the general one.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(LegalPageType::values())],
            'service_id' => ['nullable', 'integer', 'exists:service_categories,id'],
        ]);

        return $this->service->page(
            $validated['type'],
            isset($validated['service_id']) ? (int) $validated['service_id'] : null,
        );
    }
}