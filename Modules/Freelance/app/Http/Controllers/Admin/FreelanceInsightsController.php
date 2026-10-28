<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Models\FreelanceDispute;

/**
 * Operations and improvement figures for staff (FL-805 / FL-808), from what is already kept. Read only; nothing here acts on anyone.
 * The money side is `freelance-reports/summary`.
 */
class FreelanceInsightsController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-reports', [
            ['view', ['operations', 'improvement']],
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private static function period(Request $request): array
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'country_id' => ['nullable', 'integer']]);

        return [isset($f['from']) ? Carbon::parse($f['from'])->startOfDay() : now()->startOfMonth(), isset($f['to']) ? Carbon::parse($f['to'])->endOfDay() : now()->endOfDay()];
    }

    /** @return array<string, int> */
    private static function counts(\Illuminate\Database\Query\Builder $q, string $column): array
    {
        return $q->select($column, DB::raw('count(*) as n'))->groupBy($column)->pluck('n', $column)->map(fn ($n) => (int) $n)->all();
    }

    /**
     * GET freelance-reports/operations[?country_id=&from=&to=] — how the work is going: projects, offers and agreements by status, how
     * fast projects get an offer and a hire, how many parts and disputes, and the share of finished work.
     */
    public function operations(Request $request): JsonResponse
    {
        [$from, $to] = self::period($request);
        $country = $request->integer('country_id') ?: null;
        $projects = fn () => DB::table('freelance_projects')->when($country, fn ($q) => $q->where('country_id', $country));
        $inProjects = fn () => $projects()->select('id');

        $startedAgreements = DB::table('freelance_agreements')->whereIn('project_id', $inProjects())->whereBetween('activated_at', [$from, $to])->count();
        $completed = DB::table('freelance_agreements')->whereIn('project_id', $inProjects())->where('status', 'completed')->whereBetween('completed_at', [$from, $to])->count();
        $hours = fn ($rows, string $from, string $to) => $rows->isEmpty() ? null : (int) round($rows->avg(fn ($r) => max(0, Carbon::parse($r->{$from})->diffInHours(Carbon::parse($r->{$to}), false))));
        $hire = DB::table('freelance_agreements as a')->join('freelance_projects as p', 'p.id', '=', 'a.project_id')->whereIn('a.project_id', $inProjects())
            ->whereBetween('a.activated_at', [$from, $to])->whereNotNull('p.published_at')->get(['p.published_at as published', 'a.activated_at as hired']);
        $firstOffer = DB::table('freelance_offers as o')->join('freelance_projects as p', 'p.id', '=', 'o.project_id')->whereIn('o.project_id', $inProjects())
            ->whereBetween('p.published_at', [$from, $to])->groupBy('p.id', 'p.published_at')->get(['p.published_at as published', DB::raw('min(o.created_at) as first_offer')]);

        return ApiResponse::success([
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'projects_by_status' => self::counts($projects(), 'status'),
            'projects_created' => $projects()->whereBetween('created_at', [$from, $to])->count(),
            'offers_by_status' => self::counts(DB::table('freelance_offers')->whereIn('project_id', $inProjects())->whereBetween('created_at', [$from, $to]), 'status'),
            'agreements_by_status' => self::counts(DB::table('freelance_agreements')->whereIn('project_id', $inProjects())->whereBetween('created_at', [$from, $to]), 'status'),
            'agreements_started' => $startedAgreements,
            'agreements_completed' => $completed,
            'avg_hours_to_hire' => $hours($hire, 'published', 'hired'),
            'avg_hours_to_first_offer' => $hours($firstOffer, 'published', 'first_offer'),
            'milestones_by_status' => self::counts(DB::table('freelance_milestones as m')->join('freelance_projects as p', 'p.id', '=', 'm.project_id')->when($country, fn ($q) => $q->where('p.country_id', $country))->whereBetween('m.created_at', [$from, $to]), 'm.status'),
            'disputes_opened' => FreelanceDispute::query()->whereIn('project_id', $inProjects())->whereBetween('created_at', [$from, $to])->count(),
            'disputes_by_category' => self::counts(DB::table('freelance_disputes')->whereIn('project_id', $inProjects())->whereBetween('created_at', [$from, $to]), 'category'),
        ], __('api.retrieved'));
    }

    /**
     * GET freelance-reports/improvement[?country_id=&from=&to=] — why people leave (FL-808): the reasons given when work was called off,
     * the reasons for a low satisfaction score, and the offers and invitations that were turned down. Counted per side, so the product
     * can see what to fix. Information only.
     */
    public function improvement(Request $request): JsonResponse
    {
        [$from, $to] = self::period($request);
        $country = $request->integer('country_id') ?: null;
        $inProjects = fn () => DB::table('freelance_projects')->when($country, fn ($q) => $q->where('country_id', $country))->select('id');

        $cancel = DB::table('freelance_cancellation_reasons')->whereIn('project_id', $inProjects())->whereBetween('created_at', [$from, $to])
            ->select('actor_type', 'reason_code', DB::raw('count(*) as n'))->groupBy('actor_type', 'reason_code')->get();
        $byCancel = ['client' => [], 'freelancer' => []];
        foreach ($cancel as $r) {
            $byCancel[$r->actor_type][$r->reason_code] = (int) $r->n;
        }

        $surveys = DB::table('freelance_satisfaction_surveys')->whereIn('project_id', $inProjects())->whereBetween('created_at', [$from, $to])->get(['side', 'score', 'reasons']);
        $notSatisfied = ['client' => [], 'freelancer' => []];
        foreach ($surveys as $s) {
            foreach (json_decode((string) $s->reasons, true) ?: [] as $reason) {
                $notSatisfied[$s->side][$reason] = ($notSatisfied[$s->side][$reason] ?? 0) + 1;
            }
        }
        $answered = fn (string $side) => $surveys->where('side', $side);

        return ApiResponse::success([
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'cancellations' => ['by_client' => $byCancel['client'], 'by_freelancer' => $byCancel['freelancer']],
            'dissatisfaction' => [
                'client' => ['answers' => $answered('client')->count(), 'not_satisfied' => $answered('client')->where('score', '<=', 3)->count(), 'reasons' => $notSatisfied['client']],
                'freelancer' => ['answers' => $answered('freelancer')->count(), 'not_satisfied' => $answered('freelancer')->where('score', '<=', 3)->count(), 'reasons' => $notSatisfied['freelancer']],
            ],
            'offers' => self::counts(DB::table('freelance_offers')->whereIn('project_id', $inProjects())->whereBetween('created_at', [$from, $to])->whereIn('status', ['rejected', 'withdrawn', 'expired']), 'status'),
            'invitations' => self::counts(DB::table('freelance_project_invitations')->whereIn('project_id', $inProjects())->whereBetween('created_at', [$from, $to])->whereIn('status', ['declined', 'expired', 'withdrawn']), 'status'),
            'projects_ended_without_work' => self::counts(DB::table('freelance_projects')->when($country, fn ($q) => $q->where('country_id', $country))->whereBetween('created_at', [$from, $to])->whereIn('status', ['closed', 'cancelled', 'rejected']), 'status'),
        ], __('api.retrieved'));
    }
}
