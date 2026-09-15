<?php

namespace App\Services;

use App\Http\Resources\CurrencyResource;
use App\Repositories\CurrencyRepository;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class CurrencyService extends CatalogService
{
    protected ?string $resource = CurrencyResource::class;

    public function __construct(
        CurrencyRepository $repository,
        protected ExchangeRateService $exchangeRateService,
    ) {
        parent::__construct($repository);
    }

    public function list(?string $message = null, ?string $groupBy = null): JsonResponse
    {
        $this->exchangeRateService->syncIfStale();

        return parent::list($message, $groupBy);
    }

    public function find(int|string $id, ?string $message = null): JsonResponse
    {
        $this->exchangeRateService->syncIfStale();

        return parent::find($id, $message);
    }

    public function syncExchangeRates(): JsonResponse
    {
        $updated = $this->exchangeRateService->syncIfStale(force: true);

        return ApiResponse::success(
            ['updated' => $updated],
            __('api.updated'),
        );
    }
}
