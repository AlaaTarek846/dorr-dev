<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Http\Resources\IntegrationResource;
use Modules\Marketing\Models\MarketingIntegration;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\Features\MarketingCountry;
use Modules\Marketing\Services\Features\MarketingFeatures;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\Platforms\IntegrationService;
use Modules\Marketing\Services\Platforms\PlatformRegistry;

/** Ad platform connections (U10). */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly IntegrationService $integrations,
        private readonly MarketingAccess $access,
        private readonly PlatformRegistry $platforms,
        private readonly MarketingFeatures $features,
    ) {}

    /** GET marketing/projects/{project}/integrations — the connections + every platform and whether it is open here */
    public function index(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $country = MarketingCountry::of($request->user());

        return ApiResponse::success([
            'integrations' => IntegrationResource::collection($project->hasMany(MarketingIntegration::class, 'project_id')->latest('id')->get()),
            'platforms' => collect([...PlatformRegistry::PROVIDERS, ...PlatformRegistry::COMING])->map(fn ($p) => [
                'provider' => $p,
                'available' => in_array($p, PlatformRegistry::PROVIDERS, true) && $country !== null && $this->features->allows($country, 'platform_'.$p),
                'coming_soon' => in_array($p, PlatformRegistry::COMING, true),
                'configured' => in_array($p, PlatformRegistry::PROVIDERS, true) && $this->platforms->get($p)->configured(),
            ])->values(),
        ], __('api.retrieved'));
    }

    /** POST marketing/projects/{project}/integrations/{provider}/connect — owner; `authorize_url` to open, or connected now (sandbox) */
    public function connect(Request $request, MarketingProject $project, string $provider)
    {
        abort_unless(in_array($provider, [...PlatformRegistry::PROVIDERS, ...PlatformRegistry::COMING], true), 404);
        $country = MarketingCountry::of($request->user());
        // AT-18: a platform not open in the country is refused with a clear message.
        if ($country === null || ! $this->features->allows($country, 'platform_'.$provider)) {
            throw new MarketingException('platform_unavailable', 403);
        }
        $result = $this->integrations->connect($project, $request->user(), $provider);

        return ApiResponse::success([
            'integration' => new IntegrationResource($result['integration']),
            'authorize_url' => $result['authorize_url'],
        ], __($result['authorize_url'] === null ? 'marketing.messages.integration_connected' : 'marketing.messages.integration_continue'));
    }

    /** GET marketing/integrations/{integration} */
    public function show(Request $request, MarketingIntegration $integration)
    {
        $this->access->assert($integration->project, $request->user());

        return ApiResponse::success(new IntegrationResource($integration), __('api.retrieved'));
    }

    /** POST marketing/integrations/{integration}/account  {account_id} — owner, after OAuth found several ad accounts */
    public function account(Request $request, MarketingIntegration $integration)
    {
        $data = $request->validate(['account_id' => ['required', 'string', 'max:64']]);

        return ApiResponse::success(new IntegrationResource($this->integrations->selectAccount($integration, $request->user(), $data['account_id'])), __('marketing.messages.integration_connected'));
    }

    /** POST marketing/integrations/{integration}/disconnect — owner */
    public function disconnect(Request $request, MarketingIntegration $integration)
    {
        return ApiResponse::success(new IntegrationResource($this->integrations->disconnect($integration, $request->user())), __('marketing.messages.integration_disconnected'));
    }
}
