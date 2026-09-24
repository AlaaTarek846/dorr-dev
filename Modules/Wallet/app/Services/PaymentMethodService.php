<?php

namespace Modules\Wallet\Services;

use App\Models\Country;
use App\Services\CatalogService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Wallet\Http\Resources\PaymentMethodResource;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Repositories\PaymentMethodRepository;

class PaymentMethodService extends CatalogService
{
    protected ?string $resource = PaymentMethodResource::class;

    public function __construct(PaymentMethodRepository $repository)
    {
        parent::__construct($repository);
    }

    protected function beforeStore(array $data): array
    {
        $data['created_by'] = auth('admin_api')->id();
        $data['updated_by'] = auth('admin_api')->id();

        return $data;
    }

    protected function beforeUpdate(int|string $id, array $data): array
    {
        $data['updated_by'] = auth('admin_api')->id();

        // No credentials sent = keep the stored ones (see PaymentMethodRequest).
        if (empty($data['credentials'])) {
            unset($data['credentials']);
        }

        return $data;
    }

    /**
     * Replaces the full set of country links for a method — same "sync"
     * semantics as Laravel's own belongsToMany()->sync(), but with our
     * per-link min/max/status columns.
     *
     * @param  list<array{country_id: int, min_amount_minor?: int|null, max_amount_minor?: int|null, status?: bool}>  $links
     */
    public function syncCountries(int|string $id, array $links): JsonResponse
    {
        /** @var PaymentMethod $method */
        $method = $this->repository->query()->findOrFail($id);

        DB::transaction(function () use ($method, $links) {
            $keep = [];

            foreach ($links as $link) {
                $model = $method->countryLinks()->updateOrCreate(
                    ['country_id' => $link['country_id']],
                    [
                        'min_amount_minor' => $link['min_amount_minor'] ?? null,
                        'max_amount_minor' => $link['max_amount_minor'] ?? null,
                        'status' => $link['status'] ?? true,
                    ],
                );
                $keep[] = $model->country_id;
            }

            $method->countryLinks()->whereNotIn('country_id', $keep)->delete();
        });

        return ApiResponse::success(
            $this->transformResource($method->fresh(['translations', 'countryLinks.country'])),
            __('api.updated'),
        );
    }

    /**
     * Methods a user in this country can actually pick when topping up —
     * global + linked-to-this-country, active only, ordered by sort_order.
     */
    public function availableForCountry(Country $country): JsonResponse
    {
        $methods = PaymentMethod::query()
            ->with(['translations', 'translation'])
            ->availableForCountry($country)
            ->where('supports_topup', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(
            PaymentMethodResource::collection($methods),
            __('api.retrieved'),
        );
    }
}
