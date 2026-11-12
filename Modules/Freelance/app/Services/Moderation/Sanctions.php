<?php

namespace Modules\Freelance\Services\Moderation;

use Modules\User\Models\User;
use App\Services\General\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceSanction as Sanction;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\FreelanceChatService;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * Warnings, limits and suspensions (FL-708). Nothing here happens by itself: a member of staff issues each one with a reason, and the
 * person may appeal it once, within `sanctions.appeal_days`, to ANOTHER member of staff (the one who issued it can't decide the appeal).
 *
 *   warning     a notice that stays `warning_days`; it limits nothing
 *   chat_limit  can read, can't send messages or propose interviews (1..limit_max_days)
 *   work_limit  no new offers, projects, hiring or invitations; the work already agreed goes on (1..limit_max_days)
 *   suspension  both, until lifted or its end; needs the `freelance-sanctions.suspend` permission
 *
 * None of them touches money or the parts already agreed: wallets, withdrawals, deliveries, approvals and disputes all go on, so the
 * other person of an agreement is never hurt by it.
 */
class Sanctions
{
    public function __construct(
        private readonly FreelanceNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    public function person(string $side, int $id): Model
    {
        return $side === 'freelancer' ? Freelancer::query()->findOrFail($id) : User::query()->findOrFail($id);
    }

    /** @return array{chat: ?array, work: ?array} for each thing that may be stopped, the sanction stopping it now (the longest one) */
    public function restrictions(Model $person): array
    {
        $out = ['chat' => null, 'work' => null];
        $active = Sanction::query()->active()->for(FreelanceChatService::side($person), (int) $person->getKey())->where('type', '!=', Sanction::WARNING)->get();
        foreach ($active as $s) {
            foreach (Sanction::BLOCKS[$s->type] as $what) {
                $current = $out[$what];
                // An open end beats any date; otherwise the later end.
                if ($current === null || ($current['until'] !== null && ($s->ends_at === null || $s->ends_at->toIso8601String() > $current['until']))) {
                    $out[$what] = ['uuid' => $s->uuid, 'type' => $s->type, 'until' => $s->ends_at?->toIso8601String()];
                }
            }
        }

        return $out;
    }

    /**
     * @param  'chat'|'work'  $what
     *
     * @throws FreelanceException freelance_restricted_chat | freelance_restricted_work (403) with `until` and the sanction
     */
    public function assertAllowed(Model $person, string $what): void
    {
        $r = $this->restrictions($person)[$what];
        if ($r !== null) {
            $until = $r['until'] === null ? __('freelance.sanction_until_lifted') : Carbon::parse($r['until'])->toDateString();
            throw new FreelanceException('restricted_'.$what, 403, ['until' => $until], ['sanction' => $r['uuid'], 'type' => $r['type'], 'until' => $r['until']]);
        }
    }

    /** Staff issue one. `days` is for limits (required) and suspensions (optional: none = until lifted); a warning always gets `warning_days`. */
    public function issue(Model $person, string $type, string $reason, ?int $days, string $source, ?string $sourceRef, Admin $admin): Sanction
    {
        $reason = mb_substr(trim(strip_tags($reason)), 0, 500);
        if (mb_strlen($reason) < 5) {
            throw new FreelanceException('sanction_reason_required', 422);
        }
        $cfg = config('freelance.sanctions');
        $ends = match ($type) {
            Sanction::WARNING => now()->addDays((int) $cfg['warning_days']),
            Sanction::CHAT_LIMIT, Sanction::WORK_LIMIT => $days !== null && $days >= 1 && $days <= (int) $cfg['limit_max_days'] ? now()->addDays($days)
                : throw new FreelanceException('sanction_days', 422, ['max' => $cfg['limit_max_days']]),
            Sanction::SUSPENSION => $days === null ? null : ($days >= 1 && $days <= (int) $cfg['suspension_max_days'] ? now()->addDays($days)
                : throw new FreelanceException('sanction_days', 422, ['max' => $cfg['suspension_max_days']])),
        };
        $s = Sanction::query()->create([
            'subject_type' => FreelanceChatService::side($person), 'subject_id' => $person->getKey(), 'type' => $type, 'reason' => $reason,
            'source' => in_array($source, Sanction::SOURCES, true) ? $source : 'manual', 'source_ref' => $sourceRef ? mb_substr($sourceRef, 0, 64) : null,
            'issued_by' => $admin->id, 'starts_at' => now(), 'ends_at' => $ends,
        ]);
        $this->audit->record('freelance.sanction_issued', $person, null, ['sanction' => $s->uuid, 'type' => $type, 'ends_at' => $ends?->toIso8601String(), 'reason' => $reason], $admin);
        $this->notifier->notify($person, 'sanction.issued', ['type' => __('freelance.sanction_types.'.$type), 'reason' => $reason], ['sanction' => $s->uuid]);
        $this->refreshRisk($person);

        return $s;
    }

    /** Staff end an active one early. */
    public function lift(Sanction $s, Admin $admin, ?string $note): Sanction
    {
        if ($s->state() !== 'active') {
            throw new FreelanceException('sanction_not_active', 409);
        }
        $s->forceFill(['lifted_by' => $admin->id, 'lifted_at' => now(), 'lift_note' => $this->clean($note)])->save();
        $person = $this->person($s->subject_type, (int) $s->subject_id);
        $this->audit->record('freelance.sanction_lifted', $person, ['sanction' => $s->uuid], ['note' => $s->lift_note], $admin);
        $this->notifier->notify($person, 'sanction.lifted', ['type' => __('freelance.sanction_types.'.$s->type)], ['sanction' => $s->uuid]);
        $this->refreshRisk($person);

        return $s;
    }

    /** The person appeals their own sanction, once, within the days. */
    public function appeal(Model $me, string $uuid, string $note): Sanction
    {
        $note = mb_substr(trim(strip_tags($note)), 0, 1000);
        if (mb_strlen($note) < 5) {
            throw new FreelanceException('appeal_note_required', 422);
        }

        return DB::transaction(function () use ($me, $uuid, $note): Sanction {
            $s = Sanction::query()->for(FreelanceChatService::side($me), (int) $me->getKey())->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($s->appeal_status !== null) {
                throw new FreelanceException('appeal_exists', 409);
            }
            if (! $this->canAppeal($s)) {
                throw new FreelanceException('appeal_closed', 409);
            }
            $s->forceFill(['appeal_note' => $note, 'appealed_at' => now(), 'appeal_status' => 'open'])->save();

            return $s;
        });
    }

    /**
     * Another member of staff decides the appeal: `upheld` (stays), `reduced` (a new, earlier end) or `lifted` (ends now, and it is no
     * longer counted against the person).
     */
    public function decideAppeal(Sanction $s, Admin $admin, string $decision, ?Carbon $endsAt, ?string $note): Sanction
    {
        if ($s->appeal_status !== 'open') {
            throw new FreelanceException('appeal_not_open', 409);
        }
        if ((int) $s->issued_by === (int) $admin->id) {
            throw new FreelanceException('appeal_same_admin', 403);
        }
        $fill = ['appeal_status' => $decision, 'appeal_decided_by' => $admin->id, 'appeal_decision_note' => $this->clean($note), 'appeal_decided_at' => now()];
        if ($decision === 'reduced') {
            if ($endsAt === null || $endsAt->isPast() || ($s->ends_at !== null && $endsAt->gte($s->ends_at))) {
                throw new FreelanceException('appeal_reduce_date', 422);
            }
            $fill['ends_at'] = $endsAt;
        } elseif ($decision === 'lifted' && $s->lifted_at === null) {
            $fill += ['lifted_at' => now(), 'lifted_by' => $admin->id];
        }
        $before = ['appeal_status' => 'open', 'ends_at' => $s->ends_at?->toIso8601String()];
        $s->forceFill($fill)->save();
        $person = $this->person($s->subject_type, (int) $s->subject_id);
        $this->audit->record('freelance.sanction_appeal_decided', $person, $before, ['sanction' => $s->uuid, 'decision' => $decision, 'ends_at' => $s->ends_at?->toIso8601String()], $admin);
        $this->notifier->notify($person, 'sanction.appeal_decided', [
            'type' => __('freelance.sanction_types.'.$s->type), 'outcome' => __('freelance.appeal_outcomes.'.$decision),
        ], ['sanction' => $s->uuid]);
        $this->refreshRisk($person);

        return $s;
    }

    public function canAppeal(Sanction $s): bool
    {
        return $s->appeal_status === null && $s->lifted_at === null
            && $s->starts_at->copy()->addDays((int) config('freelance.sanctions.appeal_days'))->isFuture();
    }

    /** @return array<string, mixed> what the person sees in their app: what limits them now, and their sanctions of the last year */
    public function mine(Model $me): array
    {
        $list = Sanction::query()->for(FreelanceChatService::side($me), (int) $me->getKey())->where('starts_at', '>=', now()->subYear())->latest('id')->limit(30)->get();

        return [
            'restrictions' => $this->restrictions($me),
            'items' => $list->map(fn (Sanction $s) => $this->present($s, false))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function present(Sanction $s, bool $forStaff, array $names = []): array
    {
        $row = [
            'uuid' => $s->uuid, 'type' => $s->type, 'state' => $s->state(), 'reason' => $s->reason, 'blocks' => Sanction::BLOCKS[$s->type],
            'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at?->toIso8601String(), 'lifted_at' => $s->lifted_at?->toIso8601String(),
            'can_appeal' => $this->canAppeal($s), 'appeal_until' => $s->starts_at->copy()->addDays((int) config('freelance.sanctions.appeal_days'))->toIso8601String(),
            'appeal' => $s->appeal_status === null ? null : [
                'status' => $s->appeal_status, 'note' => $s->appeal_note, 'at' => $s->appealed_at?->toIso8601String(),
                'decision_note' => $s->appeal_decision_note, 'decided_at' => $s->appeal_decided_at?->toIso8601String(),
            ],
        ];
        if ($forStaff) {
            $row += [
                'side' => $s->subject_type, 'person_id' => (int) $s->subject_id, 'person' => $names[$s->subject_type][$s->subject_id] ?? null,
                'source' => $s->source, 'source_ref' => $s->source_ref, 'lift_note' => $s->lift_note,
                'issued_by' => $names['admin'][$s->issued_by] ?? '#'.$s->issued_by, 'issued_by_id' => (int) $s->issued_by,
                'appeal_decided_by' => $s->appeal_decided_by === null ? null : ($names['admin'][$s->appeal_decided_by] ?? '#'.$s->appeal_decided_by),
            ];
        }

        return $row;
    }

    /** @param  iterable<Sanction>  $list @return array<string, array<int, string>> the names a staff list needs */
    public function names(iterable $list): array
    {
        $list = collect($list);
        $ids = fn (string $side) => $list->where('subject_type', $side)->pluck('subject_id')->unique()->all();

        return [
            'freelancer' => Freelancer::query()->whereIn('id', $ids('freelancer'))->pluck('name', 'id')->all(),
            'client' => User::query()->whereIn('id', $ids('client'))->pluck('name', 'id')->all(),
            'admin' => Admin::query()->whereIn('id', $list->pluck('issued_by')->merge($list->pluck('appeal_decided_by'))->filter()->unique()->all())->pluck('name', 'id')->all(),
        ];
    }

    private function refreshRisk(Model $person): void
    {
        app(QualityScore::class)->refresh($person);
    }

    private function clean(?string $note): ?string
    {
        return $note === null ? null : (mb_substr(trim(strip_tags($note)), 0, 500) ?: null);
    }
}
