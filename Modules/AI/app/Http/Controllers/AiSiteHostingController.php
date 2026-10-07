<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Http\Requests\AiSiteHostingSubscribeRequest;
use Modules\AI\Http\Resources\AiSiteHostingPlanResource;
use Modules\AI\Http\Resources\AiSiteHostingResource;
use Modules\AI\Models\AiSiteHosting;
use Modules\AI\Models\AiSiteHostingPlan;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Services\Sites\AiSiteHostingService;

/** Customer side of site hosting - shared by the User and Provider guards. */
class AiSiteHostingController extends Controller
{
    public function __construct(protected AiSiteHostingService $hostings) {}

    public function plans()
    {
        $plans = AiSiteHostingPlan::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn ($p) => $p->priceFor(currentCountry()) !== null)->values();

        $domain = config('ai.sites.hosting.domain');

        return ApiResponse::success([
            'enabled' => $this->hostings->enabled(),
            'mode' => filled($domain) ? 'subdomain' : 'path',
            'domain' => filled($domain) ? $domain : null,
            'plans' => AiSiteHostingPlanResource::collection($plans),
        ]);
    }

    public function check(Request $request)
    {
        $name = strtolower(trim((string) $request->query('name', '')));
        $problem = $this->hostings->nameProblem($name);

        return ApiResponse::success([
            'name' => $name,
            'available' => $problem === null,
            'reason' => $problem,
            'message' => $problem ? __('ai.site_'.$problem) : null,
        ]);
    }

    public function show(Request $request, int $project)
    {
        $hosting = $this->hostingFor($request, $project, required: false);

        // Always an object (never null -> []), so the apps can parse it without a special case.
        return ApiResponse::success(['hosting' => $hosting ? new AiSiteHostingResource($hosting->load(['plan', 'project'])) : null]);
    }

    public function subscribe(AiSiteHostingSubscribeRequest $request, int $project)
    {
        $model = $this->project($request, $project);
        $plan = AiSiteHostingPlan::query()->find($request->integer('plan_id'));

        if ($plan === null) {
            throw new AiSiteException('plan_unavailable', 404);
        }

        $hosting = $this->hostings->subscribe($this->owner($request), $model, $plan, $request->string('subdomain')->toString());

        return ApiResponse::success(new AiSiteHostingResource($hosting->load(['plan', 'project'])), __('ai.site_hosting_started'), 201);
    }

    public function publish(Request $request, int $project)
    {
        $hosting = $this->hostings->publish($this->hostingFor($request, $project));

        return ApiResponse::success(new AiSiteHostingResource($hosting->load(['plan', 'project'])), __('ai.site_published'));
    }

    public function autoRenew(Request $request, int $project)
    {
        $data = $request->validate(['auto_renew' => ['required', 'boolean']]);
        $hosting = $this->hostings->setAutoRenew($this->hostingFor($request, $project), (bool) $data['auto_renew']);

        return ApiResponse::success(new AiSiteHostingResource($hosting->load(['plan', 'project'])));
    }

    /** Pay one more period right now (wallet PIN enforced at the route). */
    public function renew(Request $request, int $project)
    {
        $hosting = $this->hostings->renew($this->hostingFor($request, $project));

        return ApiResponse::success(new AiSiteHostingResource($hosting->load(['plan', 'project'])), __('ai.site_hosting_renewed'));
    }

    protected function project(Request $request, int $id): AiSiteProject
    {
        $owner = $this->owner($request);

        return AiSiteProject::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->findOrFail($id);
    }

    protected function hostingFor(Request $request, int $projectId, bool $required = true): ?AiSiteHosting
    {
        $hosting = $this->project($request, $projectId)->hosting()->first();

        if ($hosting === null && $required) {
            throw new AiSiteException('hosting_not_found', 404);
        }

        return $hosting;
    }

    protected function owner(Request $request): Authenticatable
    {
        return $request->user('user_api') ?? $request->user('provider_api');
    }
}
