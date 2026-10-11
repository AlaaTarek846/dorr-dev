<?php

namespace Modules\Sports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Support\ParticipantType;
use Modules\Sports\Exceptions\SportsException;
use Modules\Sports\Models\SportsContest;
use Modules\Sports\Models\SportsContestWinner;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsPrediction;
use Modules\Sports\Models\SportsSetting;
use Modules\Sports\Services\ContestService;
use Modules\Sports\Services\PredictionService;
use Modules\Sports\Services\SportsChatService;

/** Predictions, contests and prizes (196), community rating (199), match rooms and sharing (195). */
class SportsPlayController extends Controller
{
    public function __construct(
        private readonly PredictionService $predictions,
        private readonly ContestService $contests,
    ) {}

    /** GET matches/{match}/play — my prediction, the crowd, open contests on it, the rating. */
    public function play(Request $request, SportsMatch $match)
    {
        $this->on();

        return ApiResponse::success($this->playData($request, $match), __('api.retrieved'));
    }

    /** POST matches/{match}/prediction {home_score?, away_score?, winner?} — until kick-off. */
    public function predict(Request $request, SportsMatch $match)
    {
        $this->on();
        $data = $request->validate([
            'home_score' => ['nullable', 'integer', 'min:0', 'max:30', 'required_with:away_score'],
            'away_score' => ['nullable', 'integer', 'min:0', 'max:30', 'required_with:home_score'],
            'winner' => ['nullable', 'in:home,draw,away'],
        ]);
        $this->predictions->predict($request->user(), $match, $data);

        return ApiResponse::success($this->playData($request, $match), __('api.updated'));
    }

    /** POST matches/{match}/rating {fun, excitement} — after the final whistle (199). */
    public function rate(Request $request, SportsMatch $match)
    {
        $this->on();
        $data = $request->validate(['fun' => ['required', 'integer', 'min:1', 'max:5'], 'excitement' => ['required', 'integer', 'min:1', 'max:5']]);
        if ($match->status !== 'finished') {
            throw new SportsException('rating_not_yet', 422);
        }
        $me = $request->user();
        DB::table('sports_ratings')->updateOrInsert(
            ['match_id' => $match->id, 'owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey()],
            ['fun' => $data['fun'], 'excitement' => $data['excitement'], 'updated_at' => now(), 'created_at' => now()],
        );

        return ApiResponse::success($this->playData($request, $match), __('api.updated'));
    }

    /** GET contests — the open ones in my country. */
    public function contests(Request $request)
    {
        $this->on();
        $me = $request->user();
        $country = (int) ($me->country_id ?? 0) ?: null;
        $rows = SportsContest::query()->open()->latest('id')->get()->filter(fn ($c) => $c->runsIn($country))->values();

        return ApiResponse::success($rows->map(fn ($c) => $this->contests->present($c, $me, $country))->all(), __('api.retrieved'));
    }

    /** GET contests/{contest} — rules, prize, the leaderboard and where I am. */
    public function contest(Request $request, SportsContest $contest)
    {
        $this->on();
        $me = $request->user();
        $country = (int) ($me->country_id ?? 0) ?: null;
        $matchIds = $contest->matchesQuery()->pluck('id');
        $board = SportsPrediction::query()->whereIn('match_id', $matchIds)->whereNotNull('settled_at')
            ->select('owner_type', 'owner_id', DB::raw('sum(points) as pts'), DB::raw('sum(case when exact then 1 else 0 end) as exacts'))
            ->groupBy('owner_type', 'owner_id')->orderByDesc('pts')->limit(20)->get();
        $names = ParticipantType::modelClassFor('user')::query()->whereIn('id', $board->where('owner_type', 'user')->pluck('owner_id'))->pluck('name', 'id');
        $mine = SportsPrediction::query()->ownedBy($me)->whereIn('match_id', $matchIds)->sum('points');

        return ApiResponse::success($this->contests->present($contest, $me, $country) + [
            'leaderboard' => $board->values()->map(fn ($r, $i) => ['rank' => $i + 1, 'name' => $names[$r->owner_id] ?? '—', 'points' => (int) $r->pts, 'exact' => (int) $r->exacts, 'me' => $r->owner_type === ParticipantType::aliasFor($me) && (int) $r->owner_id === (int) $me->getKey()])->all(),
            'my_points' => (int) $mine,
            'matches' => $contest->matchesQuery()->with(\Modules\Sports\Services\SportsPresenter::WITH)->orderBy('starts_at')->limit(30)->get()
                ->map(fn ($m) => app(\Modules\Sports\Services\SportsPresenter::class)->match($m, (string) ($request->input('timezone') ?: config('app.timezone'))))->all(),
        ], __('api.retrieved'));
    }

    /** GET prizes — what I won. */
    public function prizes(Request $request)
    {
        $me = $request->user();
        $rows = SportsContestWinner::query()->with('contest.translations')->where('owner_type', ParticipantType::aliasFor($me))->where('owner_id', $me->getKey())->latest('id')->get();

        return ApiResponse::success($rows->map(fn ($w) => [
            'contest_id' => $w->contest?->uuid, 'name' => $w->contest?->translated('name'), 'prize_type' => $w->prize_type, 'amount_minor' => $w->amount_minor,
            'currency_code' => $w->currency_code, 'status' => $w->status, 'points' => $w->points, 'paid_at' => $w->paid_at?->toIso8601String(),
        ])->all(), __('api.retrieved'));
    }

    /** POST matches/{match}/share {conversation_id, poll?} */
    public function share(Request $request, SportsMatch $match, SportsChatService $chat)
    {
        $data = $request->validate(['conversation_id' => ['required', 'string'], 'poll' => ['sometimes', 'boolean']]);
        $conversation = ChatConversation::query()->where('uuid', $data['conversation_id'])->firstOrFail();
        $r = $chat->share($request->user(), $match, $conversation, (bool) ($data['poll'] ?? false));

        return ApiResponse::created(['message_id' => $r['message']->uuid, 'poll_id' => $r['poll']?->uuid, 'conversation_id' => $conversation->uuid], __('api.created'));
    }

    /** POST matches/{match}/room {members[]} — watch together (195). */
    public function room(Request $request, SportsMatch $match, SportsChatService $chat, ConversationService $conversations)
    {
        $data = $request->validate(['members' => ['required', 'array', 'min:1', 'max:200'], 'members.*' => ['integer', 'distinct']]);
        $me = $request->user();
        $r = $chat->room($me, $match, array_map('intval', $data['members']));

        return ApiResponse::created(['conversation' => $conversations->resource($me, $r['participant']), 'not_added' => $r['not_added']], __('api.created'));
    }

    /** @return array<string, mixed> */
    private function playData(Request $request, SportsMatch $match): array
    {
        $me = $request->user();
        $country = (int) ($me->country_id ?? 0) ?: null;
        $rating = DB::table('sports_ratings')->where('match_id', $match->id)->selectRaw('count(*) as n, avg(fun) as fun, avg(excitement) as ex')->first();
        $myRating = DB::table('sports_ratings')->where('match_id', $match->id)->where('owner_type', ParticipantType::aliasFor($me))->where('owner_id', $me->getKey())->first();
        $contests = SportsContest::query()->open()->where(fn ($q) => $q->where('match_id', $match->id)
            ->orWhere(fn ($q) => $q->where('scope', 'round')->where('competition_id', $match->competition_id)->where('round', $match->round))
            ->orWhere(fn ($q) => $q->where('scope', 'competition')->where('competition_id', $match->competition_id)))->get()
            ->filter(fn ($c) => $c->runsIn($country))->values();

        return [
            'enabled' => (bool) SportsSetting::current()->predictions_enabled,
            'locked' => $match->status !== 'scheduled' || ($match->starts_at?->lte(now()) ?? true),
            'mine' => $this->predictions->present(SportsPrediction::query()->ownedBy($me)->where('match_id', $match->id)->first()),
            'crowd' => $this->predictions->crowd($match),
            'contests' => $contests->map(fn ($c) => $this->contests->present($c, $me, $country))->all(),
            // A community rating — never an official one (199).
            'rating' => ['count' => (int) ($rating->n ?? 0), 'fun' => $rating?->fun !== null ? round((float) $rating->fun, 1) : null, 'excitement' => $rating?->ex !== null ? round((float) $rating->ex, 1) : null,
                'mine' => $myRating ? ['fun' => (int) $myRating->fun, 'excitement' => (int) $myRating->excitement] : null],
        ];
    }

    private function on(): void
    {
        if (! SportsSetting::current()->onIn(currentCountry()?->id)) {
            throw SportsException::off();
        }
    }
}
