<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\DiscoveryService;
use Modules\Marketing\Services\MarketingAccess;

class DiscoveryController extends Controller
{
    public function __construct(private readonly DiscoveryService $discovery, private readonly MarketingAccess $access) {}

    /** GET marketing/projects/{project}/discovery */
    public function show(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());

        return ApiResponse::success($this->discovery->state($project), __('api.retrieved'));
    }

    /**
     * PUT marketing/projects/{project}/discovery  {answers: {key: value}, clear?: [key]} — `clear` empties answers
     * ("I don't know yet") for clients that can't send a JSON null.
     */
    public function update(Request $request, MarketingProject $project)
    {
        $data = $request->validate([
            'answers' => ['present', 'array', 'max:30'],
            'clear' => ['sometimes', 'array', 'max:30'],
            'clear.*' => ['string', 'max:40'],
        ]);
        $answers = $data['answers'] + array_fill_keys($data['clear'] ?? [], null);

        return ApiResponse::success($this->discovery->answer($project, $request->user(), $answers), __('marketing.messages.discovery_saved'));
    }

    /** POST marketing/projects/{project}/discovery/suggest */
    public function suggest(Request $request, MarketingProject $project)
    {
        return ApiResponse::success($this->discovery->suggest($project, $request->user()), __('marketing.messages.discovery_suggested'));
    }
}
