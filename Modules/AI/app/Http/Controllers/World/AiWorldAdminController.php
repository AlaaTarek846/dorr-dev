<?php

namespace Modules\AI\Http\Controllers\World;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AI\Exceptions\AiWorldException;
use Modules\AI\Models\World\AiWorldCostLedger;
use Modules\AI\Models\World\AiWorldCountryMode;
use Modules\AI\Models\World\AiWorldPrice;
use Modules\AI\Models\World\AiWorldProject;
use Modules\AI\Models\World\AiWorldReport;
use Modules\AI\Models\World\AiWorldSetting;

/**
 * Ops console API for DORR AI WORLD: service settings, which modes each country gets, the price per second,
 * every project with its internal cost ledger, and the complaints / deletion / impersonation reports.
 * Customers never see the ledger or provider names.
 */
class AiWorldAdminController extends Controller
{
    public function settings()
    {
        return ApiResponse::success(AiWorldSetting::current());
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'max_scenes_per_project' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'max_retries_per_scene' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'identity_threshold' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'retention_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'default_provider_id' => ['sometimes', 'nullable', 'integer', 'exists:ai_providers,id'],
        ]);

        $setting = AiWorldSetting::current();
        $setting->update($data);

        return ApiResponse::success($setting->refresh());
    }

    /** Every country with its four modes (stored value or the default). */
    public function countryModes()
    {
        $rows = Country::query()->with('currency')->get()->map(fn (Country $c) => [
            'country_id' => $c->id,
            'code' => $c->code,
            'currency' => $c->currency?->code,
            'modes' => AiWorldCountryMode::forCountry($c->id),
        ]);

        return ApiResponse::success($rows->all());
    }

    public function updateCountryModes(Request $request)
    {
        $data = $request->validate([
            'countries' => ['required', 'array', 'min:1'],
            'countries.*.country_id' => ['required', 'integer', 'exists:countries,id'],
            'countries.*.modes' => ['required', 'array'],
            'countries.*.modes.*' => ['boolean'],
        ]);

        foreach ($data['countries'] as $row) {
            foreach ($row['modes'] as $mode => $enabled) {
                if (! array_key_exists($mode, AiWorldCountryMode::DEFAULTS)) {
                    throw new AiWorldException('mode_unknown', 422);
                }

                AiWorldCountryMode::query()->updateOrCreate(['country_id' => $row['country_id'], 'mode' => $mode], ['is_enabled' => (bool) $enabled]);
            }
        }

        return $this->countryModes();
    }

    public function prices()
    {
        return ApiResponse::success(AiWorldPrice::query()->orderBy('country_id')->orderBy('quality')->get());
    }

    public function syncPrices(Request $request)
    {
        $data = $request->validate([
            'prices' => ['required', 'array', 'min:1'],
            'prices.*.country_id' => ['required', 'integer', 'exists:countries,id'],
            'prices.*.quality' => ['required', 'string', 'in:standard,premium'],
            'prices.*.price_per_second_minor' => ['required', 'integer', 'min:0'],
            'prices.*.is_active' => ['sometimes', 'boolean'],
        ]);

        foreach ($data['prices'] as $row) {
            AiWorldPrice::query()->updateOrCreate(
                ['country_id' => $row['country_id'], 'quality' => $row['quality']],
                ['price_per_second_minor' => $row['price_per_second_minor'], 'is_active' => $row['is_active'] ?? true],
            );
        }

        return $this->prices();
    }

    public function projects(Request $request)
    {
        $query = AiWorldProject::query()->withTrashed()->with('scenes')->latest('id')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('owner_id'), fn ($q, $id) => $q->where('owner_id', (int) $id));

        return ApiResponse::paginated($query, \Modules\AI\Http\Resources\World\AiWorldProjectResource::class);
    }

    public function project(int $id)
    {
        $project = AiWorldProject::query()->withTrashed()->with('scenes.versions')->find($id) ?? throw new AiWorldException('not_found', 404);

        return ApiResponse::success([
            'project' => (new \Modules\AI\Http\Resources\World\AiWorldProjectResource($project))->resolve(),
            'versions' => $project->scenes->flatMap->versions->map(fn ($v) => $v->only(['id', 'scene_id', 'number', 'status', 'identity_score', 'error_code']))->values()->all(),
            'ledger' => AiWorldCostLedger::query()->where('project_id', $id)->orderBy('id')->get(),
        ]);
    }

    public function reports(Request $request)
    {
        return ApiResponse::success(AiWorldReport::query()->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest('id')->limit(200)->get());
    }

    public function updateReport(Request $request, int $id)
    {
        $data = $request->validate(['status' => ['required', 'string', 'in:open,reviewing,resolved,dismissed'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $report = AiWorldReport::query()->find($id) ?? throw new AiWorldException('not_found', 404);
        $report->update($data + ['resolved_by' => in_array($data['status'], ['resolved', 'dismissed'], true) ? $request->user()->getAuthIdentifier() : null]);

        return ApiResponse::success($report->refresh());
    }

    /** Headline numbers for the console: projects per status and money in/out (minor units, per currency). */
    public function stats()
    {
        return ApiResponse::success([
            'projects_by_status' => AiWorldProject::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status'),
            'money' => AiWorldProject::query()->select('currency', DB::raw('sum(charged_minor) as charged'), DB::raw('sum(refunded_minor) as refunded'))->groupBy('currency')->get(),
            'open_reports' => AiWorldReport::query()->where('status', 'open')->count(),
        ]);
    }
}
