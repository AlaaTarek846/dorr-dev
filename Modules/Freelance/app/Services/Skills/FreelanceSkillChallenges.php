<?php

namespace Modules\Freelance\Services\Skills;

use App\Services\General\AuditLogger;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceSkillChallenge as Challenge;
use Modules\Freelance\Models\FreelanceSkillTest as Test;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerSkillBadge as Badge;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * FS-602 (owner 2026-11-12): the advanced and expert levels of a skill are reached by a practical challenge, not by the multiple-choice test.
 * The test of the skill says what to build (`challenge_brief`); the freelancer sends a link to what they built and a word on it; staff accept
 * it at a level (advanced, or expert — never above what was asked) or turn it down with notes the freelancer reads.
 *
 *  - Advanced needs a valid intermediate badge (or better); expert needs a valid advanced one.
 *  - One challenge waits per skill at a time; after a «no» the next one waits `freelance.skill_tests.challenge_retry_days`.
 *  - Accepting raises the badge's level and starts its validity again (FL-L08 `badge_valid_days`).
 */
class FreelanceSkillChallenges
{
    public const ACCEPTED = 'freelance.skill_challenge.accepted';

    public const REJECTED = 'freelance.skill_challenge.rejected';

    public const NOTE_MAX = 2000;

    public function __construct(
        private readonly FreelanceSkillTests $tests,
        private readonly FreelanceSkillBadges $badges,
        private readonly FreelanceNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    // -------------------------------------------------------------- the freelancer

    /**
     * Where the freelancer stands for the next level of one skill: the level a challenge is for now (null: none), the brief, the open or last
     * challenge, and when they may send one.
     *
     * @return array<string, mixed>
     */
    public function stateFor(Freelancer $f, int $skillId, ?Test $test, ?Badge $badge): array
    {
        $enabled = $test !== null && $test->challenge_enabled && $this->tests->enabled($f);
        $next = $badge === null ? null : match ($badge->level) {
            Badge::BEGINNER => null,
            Badge::INTERMEDIATE => Badge::ADVANCED,
            Badge::ADVANCED => Badge::EXPERT,
            default => null,
        };
        $last = Challenge::query()->where('freelancer_id', $f->id)->where('freelance_skill_id', $skillId)->where('status', '!=', Challenge::WITHDRAWN)->latest('id')->first();
        $wait = $this->waitUntil($last);

        return [
            'enabled' => $enabled,
            'next_level' => $enabled ? $next : null,
            'brief' => $enabled ? Test::pickText($test->challenge_brief) : null,
            'current' => $last === null ? null : $this->present($last, false),
            'retry_at' => $wait?->toIso8601String(),
            'can_send' => $enabled && $next !== null && $wait === null && $last?->status !== Challenge::SUBMITTED,
        ];
    }

    /** @param  array{target_level: string, link?: ?string, note: string}  $data */
    public function submit(Freelancer $f, int $skillId, array $data): Challenge
    {
        $test = Test::query()->where('freelance_skill_id', $skillId)->where('status', true)->first();
        if ($test === null || ! $test->challenge_enabled || ! $this->tests->enabled($f)) {
            throw new FreelanceException('skill_challenge_off', 404);
        }

        return DB::transaction(function () use ($f, $skillId, $test, $data) {
            // One at a time: the lock keeps a double tap from sending two.
            Freelancer::query()->whereKey($f->id)->lockForUpdate()->first();
            $badge = $this->badges->held($f, $skillId);
            $state = $this->stateFor($f, $skillId, $test, $badge);
            if ($state['current']['status'] ?? null) {
                if ($state['current']['status'] === Challenge::SUBMITTED) {
                    throw new FreelanceException('skill_challenge_open', 409);
                }
            }
            if ($state['retry_at'] !== null) {
                throw new FreelanceException('skill_challenge_wait', 422, ['date' => substr((string) $state['retry_at'], 0, 10)]);
            }
            if ($state['next_level'] === null || $data['target_level'] !== $state['next_level']) {
                throw new FreelanceException('skill_challenge_level', 422);
            }

            return Challenge::query()->create([
                'freelancer_id' => $f->id, 'freelance_skill_id' => $skillId, 'target_level' => $data['target_level'],
                'link' => isset($data['link']) && trim((string) $data['link']) !== '' ? trim((string) $data['link']) : null,
                'note' => mb_substr(trim(strip_tags((string) $data['note'])), 0, self::NOTE_MAX),
                'status' => Challenge::SUBMITTED,
            ]);
        });
    }

    /** The freelancer takes back a challenge staff have not decided yet. */
    public function withdraw(Freelancer $f, string $uuid): Challenge
    {
        return DB::transaction(function () use ($f, $uuid) {
            $c = Challenge::query()->where('uuid', $uuid)->where('freelancer_id', $f->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== Challenge::SUBMITTED) {
                throw new FreelanceException('skill_challenge_decided', 409);
            }
            $c->update(['status' => Challenge::WITHDRAWN]);

            return $c;
        });
    }

    // -------------------------------------------------------------- staff (FS-605)

    /** @return LengthAwarePaginator<int, Challenge> the challenges, waiting ones first (oldest first), then the decided ones (newest first) */
    public function queue(?string $status, ?string $search, int $perPage): LengthAwarePaginator
    {
        return Challenge::query()->with(['freelancer:id,uuid,name,phone', 'skill.translations'])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($search !== null && trim($search) !== '', fn ($q) => $q->whereHas('freelancer', fn ($f) => $f->where('name', 'like', '%'.addcslashes(trim((string) $search), '%_\\').'%')->orWhere('phone', 'like', '%'.addcslashes(trim((string) $search), '%_\\').'%')))
            ->orderByRaw("case status when 'submitted' then 0 else 1 end")
            ->orderByRaw("case when status = 'submitted' then created_at end asc")
            ->orderByDesc('decided_at')->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @return array<string, int> how many challenges are in each state */
    public function counts(): array
    {
        $rows = Challenge::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return collect(Challenge::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($rows[$s] ?? 0)])->all() + ['all' => (int) $rows->sum()];
    }

    /**
     * Staff decide. `accept` gives `level` (advanced or expert, not above the target) and starts the badge's validity again; `reject` needs notes.
     * 409 skill_challenge_decided when it was already decided or taken back.
     */
    public function decide(string $uuid, int $adminId, string $decision, ?string $level, ?string $note): Challenge
    {
        $c = DB::transaction(function () use ($uuid, $adminId, $decision, $level, $note) {
            $c = Challenge::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($c->status !== Challenge::SUBMITTED) {
                throw new FreelanceException('skill_challenge_decided', 409);
            }
            $note = $note === null ? null : (trim(strip_tags($note)) ?: null);
            if ($decision === 'reject') {
                if ($note === null) {
                    throw new FreelanceException('skill_challenge_note_required', 422);
                }
                $c->update(['status' => Challenge::REJECTED, 'decision_note' => mb_substr($note, 0, 1000), 'decided_by_admin_id' => $adminId, 'decided_at' => now()]);
                $this->audit->record(self::REJECTED, $c, null, ['by_admin_id' => $adminId]);

                return $c;
            }

            $level ??= $c->target_level;
            if (! in_array($level, Challenge::TARGETS, true) || Badge::rank($level) > Badge::rank($c->target_level)) {
                throw new FreelanceException('skill_challenge_level', 422);
            }
            $badge = Badge::query()->where('freelancer_id', $c->freelancer_id)->where('freelance_skill_id', $c->freelance_skill_id)->lockForUpdate()->first();
            if ($badge === null || ! $badge->isValid()) {
                // The badge it builds on ran out or was taken back meanwhile: nothing to raise.
                throw new FreelanceException('skill_challenge_badge_gone', 409);
            }
            $days = (int) config('freelance.skill_tests.badge_valid_days', 365);
            if (Badge::rank($level) > Badge::rank($badge->level)) {
                $badge->update(['level' => $level, 'expires_at' => $days > 0 ? now()->addDays($days) : null]);
            }
            $c->update(['status' => Challenge::ACCEPTED, 'granted_level' => $level, 'decision_note' => $note === null ? null : mb_substr($note, 0, 1000), 'decided_by_admin_id' => $adminId, 'decided_at' => now()]);
            $this->audit->record(self::ACCEPTED, $c, null, ['level' => $level, 'by_admin_id' => $adminId]);

            return $c;
        });

        $c->load(['freelancer', 'skill.translations']);
        if ($c->freelancer !== null) {
            $skill = (string) $c->skill?->translatedName();
            $c->status === Challenge::ACCEPTED
                ? $this->notifier->notify($c->freelancer, 'challenge.accepted', ['skill' => $skill, 'level' => __('freelance.skill_levels.'.$c->granted_level)], ['screen' => 'skill_tests'])
                : $this->notifier->notify($c->freelancer, 'challenge.rejected', ['skill' => $skill], ['screen' => 'skill_tests']);
        }

        return $c;
    }

    // -------------------------------------------------------------- shape

    /** @return array<string, mixed> */
    public function present(Challenge $c, bool $staff): array
    {
        return [
            'uuid' => $c->uuid,
            'target_level' => $c->target_level,
            'link' => $c->link,
            'note' => $c->note,
            'status' => $c->status,
            'granted_level' => $c->granted_level,
            'decision_note' => $c->decision_note,
            'sent_at' => $c->created_at?->toIso8601String(),
            'decided_at' => $c->decided_at?->toIso8601String(),
            'can_withdraw' => $c->status === Challenge::SUBMITTED,
        ] + ($staff ? [
            'skill' => ['id' => $c->freelance_skill_id, 'name' => $c->skill?->translatedName()],
            'freelancer' => $c->freelancer === null ? null : ['id' => $c->freelancer->id, 'uuid' => $c->freelancer->uuid, 'name' => $c->freelancer->name, 'phone' => $c->freelancer->phone],
            'badge' => ($b = Badge::query()->where('freelancer_id', $c->freelancer_id)->where('freelance_skill_id', $c->freelance_skill_id)->first()) === null ? null
                : ['level' => $b->level, 'score_percent' => $b->score_percent, 'valid' => $b->isValid(), 'expires_at' => $b->expires_at?->toIso8601String()],
            'brief' => Test::pickText(Test::query()->where('freelance_skill_id', $c->freelance_skill_id)->value('challenge_brief')),
        ] : []);
    }

    private function waitUntil(?Challenge $last): ?\Illuminate\Support\Carbon
    {
        if ($last === null || $last->status !== Challenge::REJECTED || $last->decided_at === null) {
            return null;
        }
        $until = $last->decided_at->copy()->addDays((int) config('freelance.skill_tests.challenge_retry_days', 14));

        return $until->isFuture() ? $until : null;
    }
}
