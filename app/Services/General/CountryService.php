<?php

namespace App\Services\General;

use App\Services\CatalogService;
use App\Http\Resources\General\CountryResource;
use App\Repositories\General\CountryRepository;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class CountryService extends CatalogService
{
    protected ?string $resource = CountryResource::class;

    public function __construct(CountryRepository $repository)
    {
        parent::__construct($repository);
    }

    /**
     * The caller's country (by IP), in the same dropdown shape — so a
     * pre-login client can preselect the right dial code without needing
     * the full country list first.
     */
    public function detect(): JsonResponse
    {
        $code = getCountryCodeByIp();

        /** @var CountryRepository $repository */
        $repository = $this->repository;
        $countries = $repository->dropdown();

        $country = $countries->firstWhere('code', $code)
            ?? $countries->firstWhere('is_default', true)
            ?? $countries->first();

        return ApiResponse::success($country, __('api.retrieved'));
    }
}
