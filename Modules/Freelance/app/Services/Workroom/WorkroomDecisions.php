<?php

namespace Modules\Freelance\Services\Workroom;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceWorkroomDecision as Decision;
use Modules\Freelance\Models\FreelanceWorkroomTask as Side;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * The Workroom's decision log (FS-150c). A side proposes a decision (a detail the two agree on: "the logo is blue"), the other side agrees or
 * declines it. A proposal can be changed or withdrawn by its writer while it waits; once it is answered it is the record and never changes -
 * a new decision that supersedes it replaces it (the old one becomes `superseded` when the new one is agreed). A decision does not change the
 * agreement's money or parts (that is a change request). After the agreement ends the decisions are only read.
 */
class WorkroomDecisions
{
    public const TITLE_MAX = 160;

    public const BODY_MAX = 2000;

    public const NOTE_MAX = 300;

    public function __construct(private readonly FreelanceNotifier $notifier) {}

    // -------------------------------------------------------------- reading

    /** @param  array{status?: ?string, milestone?: ?string, mine?: ?bool, waiting?: ?bool}  $filters */
    public function list(WorkroomContext $c, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Decision::query()->where('project_id', $c->project->id)->with(['milestone', 'supersedes:id,uuid,title', 'supersededBy:id,uuid,title,supersedes_id']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['milestone'])) {
            $query->whereHas('milestone', fn ($q) => $q->where('uuid', $filters['milestone']));
        }
        if (! empty($filters['mine'])) {
            $query->where('proposed_by_side', $c->side);
        }
        if (! empty($filters['waiting'])) {
            // What waits for my answer: proposed by the other side.
            $query->where('status', Decision::PROPOSED)->where('proposed_by_side', $c->otherSide());
        }

        // What waits first, then the newest answered.
        return $query->orderByRaw("case status when 'proposed' then 0 else 1 end")->orderByDesc('id')->paginate($perPage);
    }

    /** @return array{total: int, proposed: int, agreed: int, declined: int, superseded: int, waiting_for_me: int} */
    public function summary(WorkroomContext $c): array
    {
        $counts = Decision::query()->where('project_id', $c->project->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $waiting = Decision::query()->where('project_id', $c->project->id)->where('status', Decision::PROPOSED)->where('proposed_by_side', $c->otherSide())->count();

        return [
            'total' => (int) $counts->sum(),
            'proposed' => (int) ($counts[Decision::PROPOSED] ?? 0),
            'agreed' => (int) ($counts[Decision::AGREED] ?? 0),
            'declined' => (int) ($counts[Decision::DECLINED] ?? 0),
            'superseded' => (int) ($counts[Decision::SUPERSEDED] ?? 0),
            'waiting_for_me' => $waiting,
        ];
    }

    public function find(WorkroomContext $c, string $uuid): Decision
    {
        return Decision::query()->where('project_id', $c->project->id)->where('uuid', $uuid)
            ->with(['milestone', 'supersedes:id,uuid,title', 'supersededBy:id,uuid,title,supersedes_id'])->firstOrFail();
    }

    // -------------------------------------------------------------- writing

    /** @param  array{title: string, body: string, milestone?: ?string, supersedes?: ?string}  $data */
    public function propose(WorkroomContext $c, array $data): Decision
    {
        $decision = DB::transaction(function () use ($c, $data): Decision {
            WorkroomSupport::lockActive($c);
            $replaced = $this->replaced($c, $data['supersedes'] ?? null);

            $decision = Decision::query()->create([
                'project_id' => $c->project->id,
                'agreement_id' => $c->agreement->id,
                'milestone_id' => WorkroomSupport::milestone($c, $data['milestone'] ?? null)?->id,
                'title' => $this->title($data['title']),
                'body' => $this->body($data['body']),
                'status' => Decision::PROPOSED,
                'proposed_by_side' => $c->side,
                'proposed_by_id' => (int) $c->actor->getKey(),
                // The one who proposes has agreed by proposing.
                $c->side.'_ack_at' => now(),
                'supersedes_id' => $replaced?->id,
            ]);
            $this->record($c, 'workroom.decision.proposed', $decision, ['supersedes' => $replaced?->uuid]);

            return $decision;
        });

        $this->after($c, $decision, 'created', 'decision_proposed', $c->otherSide());

        return $this->find($c, $decision->uuid);
    }

    /** @param  array<string, mixed>  $data  only the keys present are changed (`milestone` may be null); only while it waits for an answer */
    public function update(WorkroomContext $c, Decision $decision, array $data): Decision
    {
        $decision = DB::transaction(function () use ($c, $decision, $data): Decision {
            WorkroomSupport::lockActive($c);
            $decision = $this->lock($c, $decision);
            $this->assertProposer($c, $decision);
            $this->assertWaiting($decision);

            $fill = [];
            if (array_key_exists('title', $data)) {
                $fill['title'] = $this->title((string) $data['title']);
            }
            if (array_key_exists('body', $data)) {
                $fill['body'] = $this->body((string) $data['body']);
            }
            if (array_key_exists('milestone', $data)) {
                $fill['milestone_id'] = WorkroomSupport::milestone($c, $data['milestone'])?->id;
            }
            $decision->fill($fill)->save();
            $this->record($c, 'workroom.decision.updated', $decision, ['changed' => array_keys($fill)]);

            return $decision;
        });

        $this->after($c, $decision, 'updated');

        return $this->find($c, $decision->uuid);
    }

    /** The other side agrees: it is now the record, and the decision it replaces (if any) is superseded. */
    public function agree(WorkroomContext $c, Decision $decision, ?string $note): Decision
    {
        $decision = DB::transaction(function () use ($c, $decision, $note): Decision {
            WorkroomSupport::lockActive($c);
            $decision = $this->lock($c, $decision);
            $this->assertAnswerer($c, $decision);
            $this->assertWaiting($decision);

            $decision->forceFill([$c->side.'_ack_at' => now(), 'status' => Decision::AGREED, 'answered_at' => now(), 'answer_note' => $this->note($note)])->save();
            $this->record($c, 'workroom.decision.agreed', $decision, []);
            if ($decision->supersedes_id !== null) {
                $old = Decision::query()->where('project_id', $c->project->id)->lockForUpdate()->find($decision->supersedes_id);
                if ($old !== null && $old->status === Decision::AGREED) {
                    $old->forceFill(['status' => Decision::SUPERSEDED])->save();
                    $this->record($c, 'workroom.decision.superseded', $old, ['by' => $decision->uuid]);
                }
            }

            return $decision;
        });

        $this->after($c, $decision, 'agreed', 'decision_agreed', $decision->proposed_by_side);

        return $this->find($c, $decision->uuid);
    }

    public function decline(WorkroomContext $c, Decision $decision, ?string $note): Decision
    {
        $decision = DB::transaction(function () use ($c, $decision, $note): Decision {
            WorkroomSupport::lockActive($c);
            $decision = $this->lock($c, $decision);
            $this->assertAnswerer($c, $decision);
            $this->assertWaiting($decision);

            $decision->forceFill(['status' => Decision::DECLINED, 'answered_at' => now(), 'answer_note' => $this->note($note)])->save();
            $this->record($c, 'workroom.decision.declined', $decision, []);

            return $decision;
        });

        $this->after($c, $decision, 'declined', 'decision_declined', $decision->proposed_by_side);

        return $this->find($c, $decision->uuid);
    }

    /** Takes a proposal back (only its writer, only while it waits). */
    public function withdraw(WorkroomContext $c, Decision $decision): void
    {
        $decision = DB::transaction(function () use ($c, $decision): Decision {
            WorkroomSupport::lockActive($c);
            $decision = $this->lock($c, $decision);
            $this->assertProposer($c, $decision);
            $this->assertWaiting($decision);
            $decision->delete();
            $this->record($c, 'workroom.decision.withdrawn', $decision, []);

            return $decision;
        });

        $this->after($c, $decision, 'withdrawn');
    }

    // -------------------------------------------------------------- shape

    /** @return array<string, mixed> */
    public function present(Decision $d, WorkroomContext $c): array
    {
        $open = $c->writable();
        $waiting = $open && $d->status === Decision::PROPOSED;
        $mine = $d->proposed_by_side === $c->side;
        $milestone = $d->milestone;

        return [
            'uuid' => $d->uuid,
            'title' => $d->title,
            'body' => $d->body,
            'status' => $d->status,
            'milestone' => $milestone === null ? null : ['uuid' => $milestone->uuid, 'title' => $milestone->title, 'position' => (int) $milestone->position],
            'proposed_by_side' => $d->proposed_by_side,
            'mine' => $mine,
            'client_agreed' => $d->client_ack_at !== null,
            'freelancer_agreed' => $d->freelancer_ack_at !== null,
            'answer_note' => $d->answer_note,
            'supersedes' => $d->supersedes === null ? null : ['uuid' => $d->supersedes->uuid, 'title' => $d->supersedes->title],
            'superseded_by' => $d->supersededBy === null ? null : ['uuid' => $d->supersededBy->uuid, 'title' => $d->supersededBy->title],
            'answered_at' => $d->answered_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
            'can' => [
                'decision_edit' => $waiting && $mine,
                'decision_withdraw' => $waiting && $mine,
                'decision_agree' => $waiting && ! $mine,
                'decision_decline' => $waiting && ! $mine,
                // A decision that is agreed and not replaced yet can be replaced by a new proposal.
                'decision_supersede' => $open && $d->status === Decision::AGREED,
            ],
        ];
    }

    // -------------------------------------------------------------- inside

    private function lock(WorkroomContext $c, Decision $decision): Decision
    {
        return Decision::query()->where('project_id', $c->project->id)->lockForUpdate()->findOrFail($decision->id);
    }

    private function assertProposer(WorkroomContext $c, Decision $decision): void
    {
        if ($decision->proposed_by_side !== $c->side) {
            throw new FreelanceException('workroom_decision_forbidden', 403);
        }
    }

    /** Only the side that did not propose answers. */
    private function assertAnswerer(WorkroomContext $c, Decision $decision): void
    {
        if ($decision->proposed_by_side === $c->side) {
            throw new FreelanceException('workroom_decision_forbidden', 403);
        }
    }

    private function assertWaiting(Decision $decision): void
    {
        if ($decision->status !== Decision::PROPOSED) {
            throw new FreelanceException('workroom_decision_locked', 409);
        }
    }

    /** The agreed decision a new one replaces (422 when it is not an agreed decision of this project). */
    private function replaced(WorkroomContext $c, ?string $uuid): ?Decision
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $old = Decision::query()->where('project_id', $c->project->id)->where('uuid', $uuid)->where('status', Decision::AGREED)->lockForUpdate()->first();
        if ($old === null) {
            throw new FreelanceException('workroom_decision_supersede_invalid', 422);
        }

        return $old;
    }

    private function title(string $title): string
    {
        $text = trim(strip_tags($title));
        if (mb_strlen($text) < 3) {
            throw new FreelanceException('workroom_title_required', 422);
        }

        return mb_substr($text, 0, self::TITLE_MAX);
    }

    private function body(string $body): string
    {
        $text = trim(strip_tags($body));
        if ($text === '') {
            throw new FreelanceException('workroom_decision_body_required', 422);
        }

        return mb_substr($text, 0, self::BODY_MAX);
    }

    private function note(?string $note): ?string
    {
        $text = $note === null ? '' : trim(strip_tags($note));

        return $text === '' ? null : mb_substr($text, 0, self::NOTE_MAX);
    }

    /** @param  array<string, mixed>  $extra */
    private function record(WorkroomContext $c, string $type, Decision $decision, array $extra): void
    {
        WorkroomSupport::record($c, $type, ['decision' => $decision->uuid, 'title' => $decision->title] + array_filter($extra, fn ($v) => $v !== null));
    }

    /** After the step is saved: tell the side that has to answer (or the one that proposed), and the live screens of the project. */
    private function after(WorkroomContext $c, Decision $decision, string $action, ?string $event = null, ?string $to = null): void
    {
        if ($event !== null && $to !== null && $to !== $c->side) {
            $c->agreement->loadMissing(['client', 'freelancer']);
            $receiver = $to === Side::CLIENT ? $c->agreement->client : $c->agreement->freelancer;
            if ($receiver !== null) {
                $this->notifier->notify($receiver, 'workroom.'.$event, ['project' => (string) $c->project->title, 'title' => $decision->title], ['project' => $c->project->uuid, 'decision' => $decision->uuid]);
            }
        }
        WorkroomSupport::changed($c, 'decision', $action, $decision->uuid);
    }
}
