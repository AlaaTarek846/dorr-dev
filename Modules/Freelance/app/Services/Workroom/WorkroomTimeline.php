<?php

namespace Modules\Freelance\Services\Workroom;

use App\Support\Api\ApiPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Modules\Freelance\Models\FreelanceDispute as Dispute;
use Modules\Freelance\Models\FreelanceProjectEvent;
use Modules\Freelance\Services\Projects\ApprovalTrail;
use Modules\User\Models\User;

/**
 * The Workroom's timeline (FS-150c): one list, newest first, of what happened on the project - the Workroom's own steps (tasks, files, notes,
 * decisions, from the project record) and the agreement's record of approvals (parts funded / delivered / approved, changes, disputes…,
 * FL-406). Each side sees the events meant for it (`audience`): a `client` event never reaches the freelancer, and `team` never the client.
 * No text of a note and no amount is ever in it. Reading only; every row already exists on the record.
 */
class WorkroomTimeline
{
    /** The most rows read from each source for one list (a project's record is far smaller than this). */
    public const CAP = 500;

    /** The keys of an event's payload that are safe to show to both sides. */
    private const DETAILS = ['from', 'to', 'version', 'assignee_side', 'changed', 'supersedes', 'by'];

    public function __construct(private readonly ApprovalTrail $trail) {}

    /**
     * @param  array{kind?: ?string, mine?: ?bool}  $filters
     */
    public function list(WorkroomContext $c, array $filters, int $perPage): LengthAwarePaginator
    {
        $rows = $this->workroomRows((int) $c->project->id, $c->side)->concat($this->approvalRows((int) $c->project->id, (int) $c->agreement->id, $c->side));

        if (! empty($filters['kind'])) {
            $rows = $rows->where('kind', $filters['kind']);
        }
        if (! empty($filters['mine'])) {
            $rows = $rows->where('mine', true);
        }

        $rows = $rows->sort(fn (array $a, array $b) => [$b['ts'], $b['n']] <=> [$a['ts'], $a['n']])
            ->map(fn (array $r) => array_diff_key($r, ['ts' => 1, 'n' => 1]))->values();

        return ApiPaginator::slice($rows, $perPage, 100);
    }

    /**
     * FS-150f: the same list for the staff reading a dispute - newest first, only what both sides share (never a team-only row), no viewer
     * (`mine` is always false). The caller writes the audit record.
     *
     * @return list<array<string, mixed>>
     */
    public function forDispute(Dispute $d): array
    {
        return $this->workroomRows((int) $d->project_id, null)->concat($this->approvalRows((int) $d->project_id, (int) $d->agreement_id, 'admin'))
            ->sort(fn (array $a, array $b) => [$b['ts'], $b['n']] <=> [$a['ts'], $a['n']])
            ->map(fn (array $r) => array_diff_key($r, ['ts' => 1, 'n' => 1]))->values()->all();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function workroomRows(int $projectId, ?string $side)
    {
        $events = FreelanceProjectEvent::query()->where('project_id', $projectId)->where('type', 'like', 'workroom.%')
            ->when($side === null, fn ($q) => $q->where('audience', 'both'), fn ($q) => $q->visibleTo($side))->orderByDesc('id')->limit(self::CAP)->get();
        $names = User::query()->whereIn('id', $events->pluck('acted_by_user_id')->filter()->unique())->pluck('name', 'id');
        // FS-206a: somebody of the freelancers' team who acted for the Owner who signed (the first name to the client, D10).
        $person = \Modules\Freelance\Services\FreelancerTeam\FreelancerTeamContext::namer($events->pluck('acted_by_freelancer_id'), $side === 'client');

        return $events->map(function (FreelanceProjectEvent $e) use ($side, $names, $person): array {
            $parts = explode('.', $e->type);
            $kind = $parts[1] ?? 'workroom';
            $payload = (array) $e->payload;
            $title = isset($payload['title']) ? (string) $payload['title'] : null;
            $subject = ['type' => $kind, 'uuid' => $payload[$kind] ?? null, 'title' => $title];
            if ($kind === 'comment') {
                $subject['on'] = isset($payload['file']) ? ['type' => 'file', 'uuid' => $payload['file']] : (isset($payload['delivery']) ? ['type' => 'delivery', 'uuid' => $payload['delivery']] : null);
            }

            return [
                'key' => 'w'.$e->id,
                'source' => 'workroom',
                'kind' => $kind,
                'action' => implode('.', array_slice($parts, 2)),
                'label' => $this->label($e->type, $title),
                'actor' => $e->actor_type,
                'mine' => $side !== null && $e->actor_type === $side,
                'by' => $e->acted_by_user_id === null ? $person($e->acted_by_freelancer_id) : ['name' => (string) ($names[$e->acted_by_user_id] ?? ''), 'team' => true],
                'subject' => $subject,
                'details' => (object) array_intersect_key($payload, array_flip(self::DETAILS)),
                'at' => $e->created_at?->toIso8601String(),
                'ts' => (int) $e->created_at?->getTimestamp(),
                'n' => (int) $e->id,
            ];
        });
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function approvalRows(int $projectId, int $agreementId, string $side)
    {
        return collect($this->trail->list($projectId, [$agreementId], $side))->take(self::CAP)->map(function (array $r): array {
            $details = (array) $r['details'];
            $kind = explode('.', (string) $r['action'])[0];

            return [
                'key' => 'a'.$r['id'],
                'source' => 'approval',
                'kind' => $kind,
                'action' => implode('.', array_slice(explode('.', (string) $r['action']), 1)),
                'label' => $r['label'],
                'actor' => $r['actor'],
                'mine' => (bool) $r['mine'],
                'by' => $r['by'],
                // Only which part it was about; the amounts and reasons of the record stay out of the timeline.
                'subject' => isset($details['milestone']) ? ['type' => 'milestone', 'uuid' => (string) $details['milestone'], 'title' => isset($details['title']) ? (string) $details['title'] : null] : null,
                'details' => (object) [],
                'at' => $r['at'],
                'ts' => $r['at'] === null ? 0 : Carbon::parse($r['at'])->getTimestamp(),
                'n' => (int) $r['id'],
            ];
        });
    }

    private function label(string $type, ?string $title): string
    {
        $key = 'freelance.timeline.'.str_replace('.', '_', $type);

        return Lang::has($key) ? __($key, ['title' => (string) $title]) : $type;
    }
}
