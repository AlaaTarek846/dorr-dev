<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\AI\Services\AiBenchmarkRunService;

class AiBenchmarkRunController extends Controller
{
    public function __construct(protected AiBenchmarkRunService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    /**
     * Business gap fix: the admin UI hardcoded "30" as the small-sample
     * warning threshold, while the real value has always been configurable
     * (config('ai.min_recommended_sample_size'), env AI_BENCHMARK_MIN_SAMPLE_SIZE)
     * - changing it in config silently left the UI showing the old number.
     * Exposes the live value so the UI never has to guess it again.
     */
    public function config()
    {
        return ApiResponse::success([
            'min_recommended_sample_size' => (int) config('ai.min_recommended_sample_size', 30),
        ]);
    }

    public function show(int $run)
    {
        return $this->service->find($run);
    }

    public function store(Request $request)
    {
        $request->validate([
            'provider_id' => ['nullable', 'integer', 'exists:ai_providers,id'],
            'domain_key' => ['nullable', 'string', 'max:60'],
        ]);

        return $this->service->trigger(
            $request->user('admin_api'),
            $request->integer('provider_id') ?: null,
            $request->string('domain_key')->value() ?: null,
        );
    }
}
