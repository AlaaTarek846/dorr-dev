<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Http\Requests\AiSiteEditRequest;
use Modules\AI\Http\Requests\AiSiteProjectStoreRequest;
use Modules\AI\Http\Resources\AiSiteOfferResource;
use Modules\AI\Http\Resources\AiSiteProjectResource;
use Modules\AI\Models\AiSiteOffer;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Services\Sites\AiSiteEntitlementService;
use Modules\AI\Services\Sites\AiSiteProjectService;

/**
 * Customer side of the website builder - shared by the User and Provider
 * guards like AiChatController. Projects are always looked up through the
 * owner, so another account's id is a plain 404.
 */
class AiSiteProjectController extends Controller
{
    public function __construct(
        protected AiSiteProjectService $sites,
        protected AiSiteEntitlementService $entitlements,
    ) {}

    /** What this account can do right now: plan allowance and the standalone offers on sale in its country. */
    public function offers(Request $request)
    {
        $owner = $this->owner($request);
        $plan = $this->entitlements->activePlanFor($owner);
        $refusal = $this->entitlements->planCreateRefusal($owner, $plan);

        $offers = AiSiteOffer::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success([
            'enabled' => (bool) config('ai.sites.enabled', true),
            'plan' => [
                'included' => $this->entitlements->planIncludesSites($plan),
                'can_create' => $refusal === null,
                'refusal' => $refusal,
                'daily_used' => $this->entitlements->usedToday($owner),
                'daily_limit' => (int) ($plan?->site_daily_generations ?? 0),
                'projects_limit' => (int) ($plan?->site_projects_limit ?? 0),
            ],
            'offers' => AiSiteOfferResource::collection($offers->filter(fn ($o) => $o->priceFor(currentCountry()) !== null)->values()),
        ]);
    }

    public function index(Request $request)
    {
        $owner = $this->owner($request);

        return ApiResponse::paginated(
            $this->owned($owner)->latest('id'),
            AiSiteProjectResource::class,
        );
    }

    public function store(AiSiteProjectStoreRequest $request)
    {
        $project = $this->sites->create(
            $this->owner($request),
            $request->brief(),
            $request->file('logo'),
            $request->file('images', []),
        );

        return ApiResponse::success(new AiSiteProjectResource($project->load('versions')), __('ai.site_queued'), 202);
    }

    /** Standalone paid site (no plan feature needed). Wallet PIN is enforced at the route. */
    public function purchase(AiSiteProjectStoreRequest $request)
    {
        $offerId = $request->integer('offer_id');

        if ($offerId <= 0) {
            throw new AiSiteException('offer_unavailable', 404);
        }

        $project = $this->sites->create(
            $this->owner($request),
            $request->brief(),
            $request->file('logo'),
            $request->file('images', []),
            $offerId,
        );

        return ApiResponse::success(new AiSiteProjectResource($project->load(['versions', 'purchase'])), __('ai.site_queued'), 202);
    }

    public function show(Request $request, int $project)
    {
        return ApiResponse::success(new AiSiteProjectResource($this->find($request, $project)->load(['versions', 'purchase'])));
    }

    public function edit(AiSiteEditRequest $request, int $project)
    {
        $updated = $this->sites->requestEdit($this->find($request, $project), $this->owner($request), $request->string('instruction')->toString());

        return ApiResponse::success(new AiSiteProjectResource($updated->load(['versions', 'purchase'])), __('ai.site_queued'), 202);
    }

    public function retry(Request $request, int $project)
    {
        $updated = $this->sites->retry($this->find($request, $project), $this->owner($request));

        return ApiResponse::success(new AiSiteProjectResource($updated->load(['versions', 'purchase'])), __('ai.site_queued'), 202);
    }

    public function restore(Request $request, int $project, int $number)
    {
        $updated = $this->sites->restore($this->find($request, $project), $number);

        return ApiResponse::success(new AiSiteProjectResource($updated->load(['versions', 'purchase'])), __('ai.site_restored'));
    }

    public function download(Request $request, int $project)
    {
        $model = $this->find($request, $project);

        if ($model->isDisabled()) {
            throw new AiSiteException('project_disabled', 403);
        }

        $path = $this->sites->zip($model);

        return response()->download($path, 'site-'.$model->id.'.zip')->deleteFileAfterSend(true);
    }

    public function destroy(Request $request, int $project)
    {
        $this->sites->delete($this->find($request, $project));

        return ApiResponse::success(null, __('ai.site_deleted'));
    }

    protected function owned(Authenticatable $owner)
    {
        return AiSiteProject::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier());
    }

    protected function find(Request $request, int $id): AiSiteProject
    {
        return $this->owned($this->owner($request))->findOrFail($id);
    }

    protected function owner(Request $request): Authenticatable
    {
        return $request->user('user_api') ?? $request->user('provider_api');
    }
}
