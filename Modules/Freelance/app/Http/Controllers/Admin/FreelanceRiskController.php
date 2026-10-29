<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceMessageReport;
use Modules\Freelance\Models\FreelanceSanction;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Moderation\QualityScore;
use Modules\User\Models\User;

/**
 * The risk desk (FL-807): the accounts the quality rules (FL-707) put at medium or high risk — freelancers and clients together — with the
 * reasons, their open reports and active sanctions, and the open reports waiting for a decision. It only shows; a warning, limit or
 * suspension is issued by staff in `freelance-sanctions`, a report is decided in `freelance-message-reports`.
 */
class FreelanceRiskController extends Controller implements HasMiddleware
{
    public function __construct(private readonly QualityScore $quality) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-risk', [
            ['view', ['accounts', 'overview']],
        ]);
    }

    /** GET freelance-risk/overview — the numbers at the top of the desk. */
    public function overview(): JsonResponse
    {
        $reports = FreelanceMessageReport::query()->where('status', FreelanceMessageReport::OPEN);

        return ApiResponse::success([
            'freelancers' => DB::table('freelancers')->whereIn('risk_level', ['medium', 'high'])->select('risk_level', DB::raw('count(*) as n'))->groupBy('risk_level')->pluck('n', 'risk_level')->map(fn ($n) => (int) $n)->all(),
            'clients' => DB::table('freelance_client_risk')->whereIn('risk_level', ['medium', 'high'])->select('risk_level', DB::raw('count(*) as n'))->groupBy('risk_level')->pluck('n', 'risk_level')->map(fn ($n) => (int) $n)->all(),
            'open_reports' => (clone $reports)->count(),
            'open_reports_by_reason' => (clone $reports)->select('reason', DB::raw('count(*) as n'))->groupBy('reason')->pluck('n', 'reason')->map(fn ($n) => (int) $n)->all(),
            'active_sanctions' => FreelanceSanction::query()->active()->count(),
        ], __('api.retrieved'));
    }

    /** GET freelance-risk/accounts[?side=freelancer|client&level=medium|high&per_page=] — high first. */
    public function accounts(Request $request): JsonResponse
    {
        $f = $request->validate(['side' => ['nullable', Rule::in(['freelancer', 'client'])], 'level' => ['nullable', Rule::in(['medium', 'high'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $levels = isset($f['level']) ? [$f['level']] : ['medium', 'high'];

        $freelancers = DB::table('freelancers')->whereIn('risk_level', $levels)->select(DB::raw("'freelancer' as side"), 'id', 'name', 'risk_level', 'quality_score', 'risk_checked_at as checked_at');
        $clients = DB::table('freelance_client_risk as r')->join('users', 'users.id', '=', 'r.user_id')->whereIn('r.risk_level', $levels)
            ->select(DB::raw("'client' as side"), 'users.id', 'users.name', 'r.risk_level', 'r.quality_score', 'r.checked_at');
        $union = match ($f['side'] ?? null) {
            'freelancer' => $freelancers,
            'client' => $clients,
            default => $freelancers->unionAll($clients),
        };
        $page = DB::query()->fromSub($union, 'risk')->orderByRaw("case risk_level when 'high' then 0 else 1 end")->orderBy('quality_score')->orderBy('id')->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(function ($row) {
            $person = $row->side === 'freelancer' ? Freelancer::query()->find($row->id) : User::query()->find($row->id);
            $r = $person === null ? null : $this->quality->for($person);
            $key = $row->side === 'freelancer' ? 'freelancer' : 'client';

            return [
                'side' => $row->side, 'id' => (int) $row->id, 'name' => $row->name, 'risk_level' => $row->risk_level, 'quality_score' => $row->quality_score === null ? null : (int) $row->quality_score,
                'reasons' => $r['reasons'] ?? [], 'inputs' => $r['inputs'] ?? [],
                'active_sanctions' => FreelanceSanction::query()->active()->for($key, (int) $row->id)->count(),
                'open_reports_against' => FreelanceMessageReport::query()->where('status', FreelanceMessageReport::OPEN)
                    ->whereHas('message', fn ($m) => $m->where('sender_type', $key)->where('sender_id', $row->id))->count(),
            ];
        })->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }
}
