<?php

namespace Modules\Sports\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\Chat\Support\ParticipantType;
use Modules\Sports\Exceptions\SportsException;
use Modules\Sports\Models\SportsCompetition;
use Modules\Sports\Models\SportsFollow;
use Modules\Sports\Models\SportsPreference;
use Modules\Sports\Models\SportsTeam;

/** Following teams, national teams and competitions in any sport, and my alert choices (189, 193, 194). */
class SportsFollowService
{
    public const MAX_FOLLOWS = 60;

    public function __construct(private readonly SportsPresenter $presenter) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Model $me): array
    {
        $rows = SportsFollow::query()->ownedBy($me)->orderBy('id')->get();
        $teams = SportsTeam::query()->with(['translations', 'sport'])->whereIn('id', $rows->where('kind', 'team')->pluck('target_id'))->get()->keyBy('id');
        $competitions = SportsCompetition::query()->with(['translations', 'sport'])->whereIn('id', $rows->where('kind', 'competition')->pluck('target_id'))->get()->keyBy('id');

        return $rows->map(function (SportsFollow $f) use ($teams, $competitions) {
            $target = $f->kind === 'team' ? $teams->get($f->target_id) : $competitions->get($f->target_id);
            if ($target === null) {
                return null;
            }

            return [
                'id' => $f->id,
                'kind' => $f->kind,
                'sport' => $target->sport?->key,
                'target' => $f->kind === 'team' ? $this->presenter->team($target) : $this->presenter->competitionRef($target),
                'alerts' => $f->alertMap(),
                'no_spoilers' => (bool) $f->no_spoilers,
            ];
        })->filter()->values()->all();
    }

    /**
     * @param  array<string, bool>|null  $alerts
     */
    public function follow(Model $me, string $kind, int $targetId, ?array $alerts = null, ?bool $noSpoilers = null): SportsFollow
    {
        $target = $kind === 'team' ? SportsTeam::query()->find($targetId) : SportsCompetition::query()->active()->find($targetId);
        if ($target === null) {
            throw SportsException::notFound();
        }
        $owner = ['owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey()];
        $row = SportsFollow::query()->firstOrNew($owner + ['kind' => $kind, 'target_id' => $targetId]);
        if (! $row->exists && SportsFollow::query()->where($owner)->count() >= self::MAX_FOLLOWS) {
            throw new SportsException('too_many_follows', 422, ['max' => self::MAX_FOLLOWS]);
        }
        $row->sport_id = $target->sport_id;
        if ($alerts !== null) {
            $row->alerts = array_intersect_key(array_map('boolval', $alerts), SportsFollow::ALERTS) + ($row->alerts ?? []);
        }
        if ($noSpoilers !== null) {
            $row->no_spoilers = $noSpoilers;
        }
        $row->save();
        $this->forget((int) $target->sport_id);

        return $row;
    }

    public function unfollow(Model $me, int $id): void
    {
        $row = SportsFollow::query()->ownedBy($me)->find($id);
        if ($row !== null) {
            $row->delete();
            $this->forget((int) $row->sport_id);
        }
    }

    public function isFollowing(Model $me, string $kind, int $targetId): ?SportsFollow
    {
        return SportsFollow::query()->ownedBy($me)->where('kind', $kind)->where('target_id', $targetId)->first();
    }

    public function preferences(Model $me): SportsPreference
    {
        return SportsPreference::query()->firstOrNew(['owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function savePreferences(Model $me, array $data): SportsPreference
    {
        $prefs = $this->preferences($me);
        $prefs->fill(array_intersect_key($data, array_flip(['no_spoilers', 'celebration', 'sounds', 'vibrate', 'reminder_minutes', 'goals_in_quiet'])));
        $prefs->save();

        return $prefs;
    }

    /** @return array<string, mixed> */
    public function presentPreferences(SportsPreference $p): array
    {
        return $p->only(['no_spoilers', 'celebration', 'sounds', 'vibrate', 'reminder_minutes', 'goals_in_quiet']);
    }

    private function forget(int $sportId): void
    {
        Cache::forget("sports.followed.{$sportId}");
        Cache::forget("sports.watched.{$sportId}");
    }
}
