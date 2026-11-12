<?php

namespace Modules\Provider\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Provider\Enums\MembershipStatus;
use Modules\Provider\Http\Resources\App\MembershipResource;
use Modules\Provider\Http\Resources\App\VehicleTypeResource;
use Modules\Provider\Models\Provider;
use Modules\Provider\Models\VehicleType;
use Modules\Provider\Services\Establishments\EstablishmentService;
use Modules\Provider\Support\ProviderPermissions;

/**
 * What the app loads on open: the establishments this person works in (to switch between them, §14) and the
 * invitations waiting for an answer; and the lists the forms need.
 */
class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Provider $provider */
        $provider = $request->user('provider_api');
        $memberships = $provider->memberships()
            ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Invited->value])
            ->whereHas('establishment')
            ->with('establishment')
            ->orderByDesc('is_owner')->orderBy('id')
            ->get();

        return ApiResponse::success([
            'memberships' => MembershipResource::collection($memberships->where('status', MembershipStatus::Active)->values()),
            'invitations' => MembershipResource::collection($memberships->where('status', MembershipStatus::Invited)->values()),
            'owns_establishment' => $memberships->contains(fn ($m) => $m->is_owner),
        ], __('api.retrieved'));
    }

    /** The services to pick from (with their image and order from the admin), vehicle types and permission groups. */
    public function options(Request $request, EstablishmentService $establishments): JsonResponse
    {
        $countryId = (int) ($request->query('country_id') ?: $request->user('provider_api')->country_id);

        return ApiResponse::success([
            'services' => $establishments->offerableServices()->with('translations')->get()->map(fn ($s) => [
                'id' => $s->id,
                'module_name' => $s->module_name,
                'name' => $s->translatedName(),
                'description' => $s->translated('description'),
                'image' => $s->getSingleMediaUrl('image') ?: null,
            ])->values(),
            'vehicle_types' => VehicleTypeResource::collection(
                VehicleType::query()->with('translations')->where('status', true)
                    ->whereHas('countries', fn ($q) => $q->whereKey($countryId))
                    ->orderBy('sort_order')->get()
            ),
            'permission_groups' => ProviderPermissions::groups(),
            'permission_templates' => ProviderPermissions::templates(),
        ], __('api.retrieved'));
    }
}
