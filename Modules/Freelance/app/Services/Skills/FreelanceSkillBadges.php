<?php

namespace Modules\Freelance\Services\Skills;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerSkillBadge;

/**
 * The verified skill badges of a freelancer (FL-L08) as the clients see them. A badge shows only while it is valid (not revoked, not past
 * its end) **and** the skill is still on the freelancer's profile — dropping a skill takes its badge off with it, so a badge never vouches
 * for something the profile no longer claims.
 */
class FreelanceSkillBadges
{
    /** The most badges a card carries — the best scores first. */
    public const SHOWN = 6;

    /** @return list<array{skill_id: int, name: ?string, score_percent: int, level: string, earned_at: string, expires_at: ?string}> the highest level first (FS-602), then the best score */
    public function of(Freelancer $f, int $limit = self::SHOWN): array
    {
        $all = $this->query()->where('b.freelancer_id', $f->id)->select(['b.freelance_skill_id as skill_id', 'b.score_percent', 'b.level', 'b.earned_at', 'b.expires_at'])
            ->orderByRaw("case b.level when 'expert' then 0 when 'advanced' then 1 when 'intermediate' then 2 else 3 end")
            ->orderByDesc('b.score_percent')->orderBy('b.freelance_skill_id')->get()
            ->pipe(fn ($rows) => FreelanceSkill::query()->with('translations')->whereIn('id', $rows->pluck('skill_id'))->get()->keyBy('id')
                ->pipe(fn ($skills) => $rows->map(fn ($r) => [
                    'skill_id' => (int) $r->skill_id,
                    'name' => $skills->get($r->skill_id)?->translatedName(),
                    'score_percent' => (int) $r->score_percent,
                    'level' => (string) $r->level,
                    'earned_at' => (string) $r->earned_at,
                    'expires_at' => $r->expires_at,
                ])->all()));

        return array_slice($all, 0, $limit);
    }

    public function count(Freelancer $f): int
    {
        return count($this->of($f, PHP_INT_MAX));
    }

    /** The valid badge of one skill, if the freelancer holds it. */
    public function held(Freelancer $f, int $skillId): ?FreelancerSkillBadge
    {
        $b = FreelancerSkillBadge::query()->where('freelancer_id', $f->id)->where('freelance_skill_id', $skillId)->first();

        return $b !== null && $b->isValid() ? $b : null;
    }

    /** The number of valid badges of the freelancer whose id is in `$column`, as a subquery for the «recommended» order — one query for a whole page. */
    public function countQuery(string $column): QueryBuilder
    {
        return $this->query()->whereColumn('b.freelancer_id', $column)->selectRaw('count(*)');
    }

    /** Valid badges of skills still on the profile. */
    private function query(): QueryBuilder
    {
        $now = now();

        return DB::table('freelancer_skill_badges as b')
            ->whereNull('b.revoked_at')
            ->where(fn ($e) => $e->whereNull('b.expires_at')->orWhere('b.expires_at', '>', $now))
            ->whereExists(fn ($x) => $x->from('freelancer_skills as fs')->whereColumn('fs.freelancer_id', 'b.freelancer_id')->whereColumn('fs.freelance_skill_id', 'b.freelance_skill_id'));
    }
}
