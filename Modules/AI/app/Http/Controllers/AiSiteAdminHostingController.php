<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\AI\Http\Resources\AiSiteHostingResource;
use Modules\AI\Models\AiSiteHosting;

/** Admin: every hosted site - look, suspend (abuse), resume, release the name. */
class AiSiteAdminHostingController extends Controller
{
    public function index(Request $request)
    {
        $query = AiSiteHosting::query()->with(['plan', 'project', 'owner'])->latest('id')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('subdomain', 'like', '%'.strtolower($request->string('search')).'%'));

        return ApiResponse::paginated($query, AiSiteHostingResource::class);
    }

    public function show(AiSiteHosting $hosting)
    {
        return ApiResponse::success(new AiSiteHostingResource($hosting->load(['plan', 'project', 'owner'])));
    }

    public function suspend(AiSiteHosting $hosting)
    {
        $hosting->forceFill(['admin_suspended' => true, 'suspended_at' => $hosting->suspended_at ?? now()])->save();

        return ApiResponse::success(new AiSiteHostingResource($hosting->load(['plan', 'project', 'owner'])), __('ai.site_disabled_done'));
    }

    /** Lifts the admin suspension; a hosting that also lapsed stays off until the customer renews. */
    public function resume(AiSiteHosting $hosting)
    {
        $hosting->forceFill(['admin_suspended' => false])->save();

        return ApiResponse::success(new AiSiteHostingResource($hosting->load(['plan', 'project', 'owner'])), __('ai.site_enabled_done'));
    }

    public function destroy(AiSiteHosting $hosting)
    {
        $hosting->delete();

        return ApiResponse::success(null, __('ai.site_deleted'));
    }
}
