<?php

namespace Modules\Sports\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Chat\Support\ParticipantType;
use Modules\Sports\Exceptions\SportsException;
use Modules\Sports\Models\SportsContest;
use Modules\Sports\Models\SportsContestWinner;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Services\ContestService;

/**
 * Prediction contests and their prizes (docs/sports-plan.md §5.2): create (draft), open, cancel,
 * settle; review winners (pay or refuse). Prizes only reach countries opened in the settings.
 */
class SportsContestController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ContestService $contests) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('sports-contests', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update', 'open', 'cancel', 'settle', 'winner']],
        ]);
    }

    public function index(Request $request)
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(['draft', 'open', 'settled', 'cancelled'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = SportsContest::query()->with(['translations', 'match.home', 'match.away', 'competition'])->withCount('winners')
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn ($c) => $this->present($c))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page), [
            'review_count' => SportsContestWinner::query()->where('status', 'review')->count(),
        ]);
    }

    public function show(SportsContest $contest)
    {
        $contest->load(['translations', 'match.home', 'match.away', 'competition'])->loadCount('winners');
        $winners = $contest->winners()->orderBy('rank')->get();
        $names = ParticipantType::modelClassFor('user')::query()->whereIn('id', $winners->where('owner_type', 'user')->pluck('owner_id'))->get(['id', 'name', 'phone'])->keyBy('id');

        return ApiResponse::success($this->present($contest) + [
            'winners' => $winners->map(fn ($w) => [
                'id' => $w->id, 'rank' => $w->rank, 'points' => $w->points, 'status' => $w->status, 'prize_type' => $w->prize_type, 'amount_minor' => $w->amount_minor,
                'currency_code' => $w->currency_code, 'note' => $w->note, 'paid_at' => $w->paid_at?->toIso8601String(),
                'account' => ['name' => $names[$w->owner_id]->name ?? null, 'phone' => $names[$w->owner_id]->phone ?? null],
            ])->all(),
        ], __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, true);
        $contest = DB::transaction(function () use ($data, $request) {
            $contest = SportsContest::query()->create($this->fields($data) + ['uuid' => (string) Str::uuid(), 'status' => 'draft', 'created_by' => $request->user()?->getKey()]);
            $this->names($contest, $data);

            return $contest;
        });

        return ApiResponse::created($this->present($contest->refresh()->load('translations')), __('api.created'));
    }

    public function update(Request $request, SportsContest $contest)
    {
        if (! in_array($contest->status, ['draft', 'open'], true)) {
            throw new SportsException('contest_locked', 422);
        }
        $data = $this->validated($request, false);
        DB::transaction(function () use ($contest, $data) {
            $contest->update($this->fields($data));
            $this->names($contest, $data);
        });

        return ApiResponse::success($this->present($contest->refresh()->load('translations')), __('api.updated'));
    }

    public function open(SportsContest $contest)
    {
        if ($contest->status !== 'draft') {
            throw new SportsException('contest_locked', 422);
        }
        $contest->update(['status' => 'open', 'starts_at' => $contest->starts_at ?? now()]);

        return ApiResponse::success($this->present($contest->load('translations')), __('api.updated'));
    }

    public function cancel(SportsContest $contest)
    {
        if ($contest->status === 'settled') {
            throw new SportsException('contest_locked', 422);
        }
        $contest->update(['status' => 'cancelled']);

        return ApiResponse::success($this->present($contest->load('translations')), __('api.updated'));
    }

    public function settle(SportsContest $contest)
    {
        $this->contests->settle($contest);

        return $this->show($contest->refresh());
    }

    /** PATCH contests/{contest}/winners/{winner} {status: paid | rejected, note?} — the review. */
    public function winner(Request $request, SportsContest $contest, SportsContestWinner $winner)
    {
        abort_unless((int) $winner->contest_id === (int) $contest->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['paid', 'rejected'])], 'note' => ['nullable', 'string', 'max:300']]);
        if ($winner->status === 'paid') {
            throw new SportsException('prize_already_paid', 422);
        }
        if ($data['status'] === 'paid') {
            $winner->update(['note' => $data['note'] ?? $winner->note]);
            $this->contests->pay($winner);
        } else {
            $winner->update(['status' => 'rejected', 'note' => $data['note'] ?? null]);
        }

        return $this->show($contest->refresh());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'scope' => [$r, Rule::in(SportsContest::SCOPES)],
            'match_id' => ['nullable', 'string', 'required_if:scope,match'],
            'competition_id' => ['nullable', 'integer', 'exists:sports_competitions,id', 'required_if:scope,round,competition'],
            'round' => ['nullable', 'string', 'max:120', 'required_if:scope,round'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'required_if:scope,competition'],
            'countries' => ['nullable', 'array'],
            'countries.*' => ['integer', 'exists:countries,id'],
            'rule' => [$r, Rule::in(SportsContest::RULES)],
            'prize_type' => [$r, Rule::in(SportsContest::PRIZES)],
            'prize_amounts' => ['nullable', 'array'],
            'prize_amounts.*' => ['integer', 'min:0'],
            'coupon' => ['nullable', 'array'],
            'coupon.kind' => ['required_with:coupon', Rule::in(['percent', 'fixed'])],
            'coupon.value' => ['nullable', 'integer', 'min:1'],
            'coupon.max_discount_minor' => ['nullable', 'integer', 'min:1'],
            'coupon.valid_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'distribution' => [$r, Rule::in(SportsContest::DISTRIBUTIONS)],
            'max_winners' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'budget_minor' => ['nullable', 'integer', 'min:1'],
            'min_account_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'auto_pay' => ['sometimes', 'boolean'],
            'review_above_minor' => ['nullable', 'integer', 'min:1'],
            'translations' => [$r, 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:160'],
            'translations.*.terms' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $out = collect($data)->except(['translations', 'match_id'])->all();
        if (array_key_exists('match_id', $data)) {
            $match = $data['match_id'] ? SportsMatch::query()->where('uuid', $data['match_id'])->first() : null;
            if ($data['match_id'] && $match === null) {
                throw SportsException::notFound();
            }
            $out['match_id'] = $match?->id;
            if ($match) {
                $out['competition_id'] = $match->competition_id;
            }
        }
        if (isset($out['prize_amounts'])) {
            $out['prize_amounts'] = collect($out['prize_amounts'])->mapWithKeys(fn ($v, $k) => [(string) $k => (int) $v])->all();
        }

        return $out;
    }

    /** @param  array<string, mixed>  $data */
    private function names(SportsContest $contest, array $data): void
    {
        foreach ($data['translations'] ?? [] as $t) {
            $contest->translations()->updateOrCreate(['locale' => $t['locale']], ['name' => $t['name'], 'terms' => $t['terms'] ?? null]);
        }
    }

    /** @return array<string, mixed> */
    private function present(SportsContest $c): array
    {
        return [
            'id' => $c->uuid, 'name' => $c->translated('name'), 'scope' => $c->scope, 'rule' => $c->rule, 'status' => $c->status,
            'match_id' => $c->match?->uuid, 'match' => $c->match ? trim(($c->match->home?->name ?? '').' – '.($c->match->away?->name ?? '')) : null,
            'competition_id' => $c->competition_id, 'competition' => $c->competition?->name, 'round' => $c->round,
            'starts_at' => $c->starts_at?->toIso8601String(), 'ends_at' => $c->ends_at?->toIso8601String(), 'countries' => array_values($c->countries ?? []),
            'prize_type' => $c->prize_type, 'prize_amounts' => $c->prize_amounts ?? (object) [], 'coupon' => $c->coupon, 'distribution' => $c->distribution,
            'max_winners' => $c->max_winners, 'budget_minor' => $c->budget_minor, 'min_account_days' => $c->min_account_days, 'auto_pay' => (bool) $c->auto_pay,
            'review_above_minor' => $c->review_above_minor, 'seed' => $c->seed, 'winners_count' => (int) ($c->winners_count ?? 0),
            'settled_at' => $c->settled_at?->toIso8601String(),
            'translations' => $c->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name, 'terms' => $t->terms])->values()->all(),
        ];
    }
}
