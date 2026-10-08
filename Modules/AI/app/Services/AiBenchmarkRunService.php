<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Modules\AI\Http\Resources\AiBenchmarkRunResource;
use Modules\AI\Repositories\AiBenchmarkRunRepository;

/**
 * Read-only listing over ai_benchmark_runs/ai_benchmark_results plus the
 * one write action this resource actually needs: triggering a new run.
 * The run itself is delegated to AiBenchmarkRunner - this service never
 * duplicates or approximates its scoring logic.
 */
class AiBenchmarkRunService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiBenchmarkRunResource::class;

    public function __construct(
        AiBenchmarkRunRepository $repository,
        protected AiBenchmarkRunner $runner,
    ) {
        parent::__construct($repository);
    }

    public function trigger(Authenticatable $admin, ?int $providerId, ?string $domainKey): JsonResponse
    {
        $run = $this->runner->run($admin, $providerId, $domainKey);

        return ApiResponse::created(new AiBenchmarkRunResource($run));
    }
}
