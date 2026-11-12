<?php

namespace Modules\Freelance\Services\Crm;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceCrmContact as Contact;
use Modules\Freelance\Models\FreelanceCrmNote as Note;
use Modules\Freelance\Models\FreelanceCrmReminder as Reminder;
use Modules\Freelance\Models\FreelanceEarning as Earning;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Plans\FreelancePerks;
use Modules\Freelance\Services\Plans\FreelancePlans;

/**
 * FS-703, the freelancer's private CRM. The list of clients is read, not stored: every client the freelancer works or worked with (an
 * agreement that is running or finished), and the clients a freelancer is still courting (an offer waiting, or an invitation to answer) — the
 * «leads». What the freelancer adds is private and the client never sees it: a star, a label (vip / regular / lead / paused), notes, and
 * follow-up reminders. The app names a client by the CRM row's `uuid`, never by the user id.
 *
 * What a plan opens is `plans.crm`: 0 = nothing (the screen is `locked` with the plan that opens it — a 200, so it can sell it), 1 = the
 * clients, their past projects, the star and notes, 2 = also labels and follow-up reminders. A lower plan hides what was written; it is kept
 * for when the freelancer comes back.
 */
class FreelancerCrm
{
    public const TABS = ['all', 'active', 'past', 'leads', 'starred'];

    public function __construct(private readonly FreelancePerks $perks, private readonly FreelancePlans $plans) {}

    // -------------------------------------------------------------- the screen

    /**
     * @param  array{tab?: ?string, search?: ?string, page?: ?int, per_page?: ?int}  $f
     * @return array<string, mixed> `locked` when the plan has no CRM; else the clients of the tab, the counts of every tab and the follow-ups due
     */
    public function overview(Freelancer $me, array $f = []): array
    {
        $level = $this->perks->crmLevel($me);
        $out = $this->frame($me, $level);
        if ($level < 1) {
            return $out;
        }

        $rows = $this->clients($me);
        $counts = ['all' => $rows->count(), 'active' => $rows->where('stage', 'active')->count(), 'past' => $rows->where('stage', 'past')->count(),
            'leads' => $rows->where('stage', 'lead')->count(), 'starred' => $rows->where('starred', true)->count()];
        $tab = in_array($f['tab'] ?? 'all', self::TABS, true) ? ($f['tab'] ?? 'all') : 'all';
        $search = mb_strtolower(trim((string) ($f['search'] ?? '')));

        $list = $rows
            ->when($tab === 'active', fn (Collection $c) => $c->where('stage', 'active'))
            ->when($tab === 'past', fn (Collection $c) => $c->where('stage', 'past'))
            ->when($tab === 'leads', fn (Collection $c) => $c->where('stage', 'lead'))
            ->when($tab === 'starred', fn (Collection $c) => $c->where('starred', true))
            ->when($search !== '', fn (Collection $c) => $c->filter(fn (array $r) => str_contains(mb_strtolower((string) $r['name']), $search)))
            ->sort(fn (array $a, array $b) => [(int) $b['starred'], (string) $b['last_activity_at']] <=> [(int) $a['starred'], (string) $a['last_activity_at']])->values();

        $per = max(1, min(50, (int) ($f['per_page'] ?? 20)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $paged = new LengthAwarePaginator($list->forPage($page, $per)->values(), $list->count(), $per, $page);

        return $out + [
            'tab' => $tab,
            'counts' => $counts,
            'clients' => $paged->getCollection()->map(fn (array $r) => $this->card($r))->all(),
            'meta' => ['total' => $paged->total(), 'page' => $paged->currentPage(), 'per_page' => $per, 'last_page' => $paged->lastPage()],
            'due' => $level >= 2 ? $this->upcoming($me) : [],
        ];
    }

    /** @return array<string, mixed> one client: the numbers, the label and star, the notes, the reminders and the projects together */
    public function show(Freelancer $me, string $uuid): array
    {
        $level = $this->need($me, 1);
        $contact = $this->contact($me, $uuid);
        $row = $this->clients($me)->firstWhere('uuid', $contact->uuid);
        abort_if($row === null, 404);
        $limit = (int) config('freelance.crm.projects_shown', 20);

        return $this->frame($me, $level) + ['client' => $this->card($row) + [
            'notes' => $this->notes($contact),
            'reminders' => $level >= 2 ? $this->reminders($contact) : [],
            'history' => $this->projects($me, (int) $contact->user_id, $limit),
            'leads' => $this->leadsOf($me, (int) $contact->user_id),
        ]];
    }

    // -------------------------------------------------------------- what the freelancer adds

    /** @param  array{label?: ?string, starred?: bool}  $data */
    public function update(Freelancer $me, string $uuid, array $data): array
    {
        $level = $this->need($me, 1);
        $contact = $this->contact($me, $uuid);
        if (array_key_exists('label', $data)) {
            $this->need($me, 2);
            $contact->label = $data['label'];
        }
        if (array_key_exists('starred', $data)) {
            $contact->starred = (bool) $data['starred'];
        }
        $contact->save();

        return $this->show($me, $uuid) + ['level' => $level];
    }

    public function addNote(Freelancer $me, string $uuid, string $body): array
    {
        $this->need($me, 1);
        $contact = $this->contact($me, $uuid);
        if (Note::query()->where('freelancer_id', $me->id)->where('user_id', $contact->user_id)->count() >= (int) config('freelance.crm.notes_max', 50)) {
            throw new FreelanceException('crm_notes_full');
        }
        Note::query()->create(['freelancer_id' => $me->id, 'user_id' => $contact->user_id, 'body' => trim($body)]);

        return $this->show($me, $uuid);
    }

    public function editNote(Freelancer $me, string $uuid, string $noteUuid, string $body): array
    {
        $this->need($me, 1);
        $contact = $this->contact($me, $uuid);
        $note = Note::query()->where('uuid', $noteUuid)->where('freelancer_id', $me->id)->where('user_id', $contact->user_id)->firstOrFail();
        $note->update(['body' => trim($body)]);

        return $this->show($me, $uuid);
    }

    public function deleteNote(Freelancer $me, string $uuid, string $noteUuid): array
    {
        $this->need($me, 1);
        $contact = $this->contact($me, $uuid);
        Note::query()->where('uuid', $noteUuid)->where('freelancer_id', $me->id)->where('user_id', $contact->user_id)->firstOrFail()->delete();

        return $this->show($me, $uuid);
    }

    public function addReminder(Freelancer $me, string $uuid, string $title, Carbon $due): array
    {
        $this->need($me, 2);
        $contact = $this->contact($me, $uuid);
        if ($due->lte(now()) || $due->gt(now()->addDays((int) config('freelance.crm.remind_ahead_days', 365)))) {
            throw new FreelanceException('crm_bad_due');
        }
        if (Reminder::query()->where('freelancer_id', $me->id)->whereNull('done_at')->count() >= (app(PlanFeatures::class)->forFreelancer($me, 'crm_reminders_max') ?? PHP_INT_MAX)) {
            throw new FreelanceException('crm_reminders_full');
        }
        Reminder::query()->create(['freelancer_id' => $me->id, 'user_id' => $contact->user_id, 'title' => trim($title), 'due_at' => $due]);

        return $this->show($me, $uuid);
    }

    /** Marks a reminder done (or open again). */
    public function finishReminder(Freelancer $me, string $uuid, string $reminderUuid, bool $done): array
    {
        $this->need($me, 2);
        $contact = $this->contact($me, $uuid);
        $r = Reminder::query()->where('uuid', $reminderUuid)->where('freelancer_id', $me->id)->where('user_id', $contact->user_id)->firstOrFail();
        $r->update(['done_at' => $done ? now() : null] + ($done ? [] : ['notified_at' => $r->due_at->isPast() ? now() : null]));

        return $this->show($me, $uuid);
    }

    public function deleteReminder(Freelancer $me, string $uuid, string $reminderUuid): array
    {
        $this->need($me, 2);
        $contact = $this->contact($me, $uuid);
        Reminder::query()->where('uuid', $reminderUuid)->where('freelancer_id', $me->id)->where('user_id', $contact->user_id)->firstOrFail()->delete();

        return $this->show($me, $uuid);
    }

    /** Every reminder still waiting, soonest first, with the client it is about (the «Follow-ups» list). @return list<array<string, mixed>> */
    public function upcoming(Freelancer $me): array
    {
        $rows = Reminder::query()->where('freelancer_id', $me->id)->whereNull('done_at')->orderBy('due_at')->limit((int) config('freelance.crm.reminders_max', 30))->get();
        $contacts = Contact::query()->where('freelancer_id', $me->id)->whereIn('user_id', $rows->pluck('user_id'))->with('client:id,name')->get()->keyBy('user_id');

        return $rows->map(fn (Reminder $r) => $this->reminder($r) + ['client' => ['uuid' => $contacts->get($r->user_id)?->uuid, 'name' => $contacts->get($r->user_id)?->client?->name]])->values()->all();
    }

    // -------------------------------------------------------------- inside

    /** @return array<string, mixed> */
    private function frame(Freelancer $me, int $level): array
    {
        $country = $me->country_id;

        return [
            'level' => $level,
            'locked' => $level < 1,
            'subscriptions_enabled' => $country !== null && $this->plans->enabled((int) $country),
            'upgrade' => $level >= 2 ? null : $this->perks->planForCrm($me, $level),
            'labels' => $level >= 2 ? (array) config('freelance.crm.labels', []) : [],
            'limits' => ['notes_max' => (int) config('freelance.crm.notes_max', 50), 'reminders_max' => (int) config('freelance.crm.reminders_max', 30)],
            'currency' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code(),
            'decimals' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->decimals(),
        ];
    }

    private function need(Freelancer $me, int $level): int
    {
        $have = $this->perks->crmLevel($me);
        if ($have < $level) {
            throw new FreelanceException('crm_locked', 403, [], ['level' => $have, 'needed' => $level, 'upgrade' => $this->perks->planForCrm($me, $have)]);
        }

        return $have;
    }

    private function contact(Freelancer $me, string $uuid): Contact
    {
        return Contact::query()->where('uuid', $uuid)->where('freelancer_id', $me->id)->firstOrFail();
    }

    /**
     * Every client the freelancer is connected to, as rows (and the CRM row of each, made when it is missing).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function clients(Freelancer $me): Collection
    {
        $agreements = Agreement::query()->where('freelancer_id', $me->id)->whereIn('status', [Agreement::ACTIVE, Agreement::COMPLETED])
            ->get(['id', 'client_id', 'status', 'activated_at', 'completed_at', 'created_at']);
        $leads = DB::table('freelance_offers as o')->join('freelance_projects as p', 'p.id', '=', 'o.project_id')->where('o.freelancer_id', $me->id)
            ->whereIn('o.status', ['submitted', 'viewed', 'shortlisted'])->whereNull('p.service_id')->groupBy('p.client_id')
            ->selectRaw('p.client_id as uid, max(o.created_at) as at')->get()->keyBy('uid');
        $invited = DB::table('freelance_project_invitations as i')->where('i.freelancer_id', $me->id)->where('i.status', 'sent')
            ->groupBy('i.client_id')->selectRaw('i.client_id as uid, max(i.created_at) as at')->get()->keyBy('uid');

        $ids = $agreements->pluck('client_id')->merge($leads->keys())->merge($invited->keys())->unique()->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $contacts = $this->contacts($me, $ids->all());
        $users = \Modules\User\Models\User::query()->whereIn('id', $ids)->get(['id', 'name'])->keyBy('id');
        $earned = Earning::query()->where('freelance_earnings.freelancer_id', $me->id)->where('freelance_earnings.status', Earning::RELEASED)
            ->join('freelance_projects as p', 'p.id', '=', 'freelance_earnings.project_id')->groupBy('p.client_id')->selectRaw('p.client_id as uid, sum(freelance_earnings.amount_minor) as n')->pluck('n', 'uid');
        $stars = DB::table('freelance_reviews as r')->join('freelance_rating_scores as s', 's.rating_id', '=', 'r.rating_id')->where('r.freelancer_id', $me->id)->where('r.side', 'client')
            ->whereNotNull('r.published_at')->groupBy('r.client_id')->selectRaw('r.client_id as uid, avg(s.stars) as avg')->pluck('avg', 'uid');
        $notes = Note::query()->where('freelancer_id', $me->id)->groupBy('user_id')->selectRaw('user_id, count(*) as n')->pluck('n', 'user_id');
        $next = Reminder::query()->where('freelancer_id', $me->id)->whereNull('done_at')->groupBy('user_id')->selectRaw('user_id, min(due_at) as at, count(*) as n')->get()->keyBy('user_id');

        return $ids->map(function ($uid) use ($agreements, $leads, $invited, $contacts, $users, $earned, $stars, $notes, $next) {
            $mine = $agreements->where('client_id', $uid);
            $active = $mine->where('status', Agreement::ACTIVE)->count();
            $done = $mine->where('status', Agreement::COMPLETED)->count();
            $stamps = $mine->flatMap(fn (Agreement $a) => [$a->completed_at, $a->activated_at, $a->created_at])->filter()
                ->push(...array_filter([$leads->get($uid)?->at ? Carbon::parse($leads->get($uid)->at) : null, $invited->get($uid)?->at ? Carbon::parse($invited->get($uid)->at) : null]));
            $contact = $contacts[$uid];

            return [
                'uuid' => $contact->uuid,
                'name' => (string) ($users->get($uid)?->name ?? ''),
                'stage' => $active > 0 ? 'active' : ($done > 0 ? 'past' : 'lead'),
                'label' => $contact->label,
                'starred' => (bool) $contact->starred,
                'projects' => $active + $done, 'active' => $active, 'completed' => $done,
                'earned_minor' => (int) ($earned[$uid] ?? 0),
                'rating' => isset($stars[$uid]) ? round((float) $stars[$uid], 1) : null,
                'notes_count' => (int) ($notes[$uid] ?? 0),
                'next_due_at' => isset($next[$uid]) ? Carbon::parse($next[$uid]->at)->toIso8601String() : null,
                'reminders_count' => (int) ($next[$uid]->n ?? 0),
                'waiting_offer' => $leads->has($uid), 'invited_you' => $invited->has($uid),
                'last_activity_at' => $stamps->max()?->toIso8601String() ?? '',
            ];
        })->values();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, Contact> the CRM row of each client, made for the ones that have none yet
     */
    private function contacts(Freelancer $me, array $userIds): array
    {
        $have = Contact::query()->where('freelancer_id', $me->id)->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        foreach ($userIds as $uid) {
            if (! $have->has($uid)) {
                $have->put($uid, Contact::query()->firstOrCreate(['freelancer_id' => $me->id, 'user_id' => $uid]));
            }
        }

        return $have->all();
    }

    /** @param  array<string, mixed>  $r @return array<string, mixed> the list card: no notes or projects, only what the list shows */
    private function card(array $r): array
    {
        return $r;
    }

    /** @return list<array<string, mixed>> */
    private function notes(Contact $c): array
    {
        return Note::query()->where('freelancer_id', $c->freelancer_id)->where('user_id', $c->user_id)->orderByDesc('id')->get()
            ->map(fn (Note $n) => ['uuid' => $n->uuid, 'body' => $n->body, 'created_at' => $n->created_at?->toIso8601String(), 'updated_at' => $n->updated_at?->toIso8601String()])->all();
    }

    /** @return list<array<string, mixed>> the open ones soonest first, then the last few done */
    private function reminders(Contact $c): array
    {
        $base = Reminder::query()->where('freelancer_id', $c->freelancer_id)->where('user_id', $c->user_id);
        $open = (clone $base)->whereNull('done_at')->orderBy('due_at')->get();
        $done = (clone $base)->whereNotNull('done_at')->orderByDesc('done_at')->limit(5)->get();

        return $open->concat($done)->map(fn (Reminder $r) => $this->reminder($r))->all();
    }

    /** @return array<string, mixed> */
    private function reminder(Reminder $r): array
    {
        return [
            'uuid' => $r->uuid, 'title' => $r->title, 'due_at' => $r->due_at?->toIso8601String(), 'done_at' => $r->done_at?->toIso8601String(),
            'overdue' => $r->done_at === null && $r->due_at !== null && $r->due_at->isPast(),
        ];
    }

    /** @return list<array<string, mixed>> the projects done together (the newest first), each with what the freelancer earned from it */
    private function projects(Freelancer $me, int $clientId, int $limit): array
    {
        $rows = Agreement::query()->where('freelancer_id', $me->id)->where('client_id', $clientId)->whereIn('status', [Agreement::ACTIVE, Agreement::COMPLETED])
            ->with('project:id,uuid,title,service_id')->orderByDesc('id')->limit($limit)->get();
        $earned = Earning::query()->where('freelancer_id', $me->id)->where('status', Earning::RELEASED)->whereIn('project_id', $rows->pluck('project_id'))
            ->groupBy('project_id')->selectRaw('project_id, sum(amount_minor) as n')->pluck('n', 'project_id');

        return $rows->map(fn (Agreement $a) => [
            'project' => $a->project?->uuid, 'title' => $a->project?->title, 'status' => $a->status, 'price_minor' => (int) $a->price_minor,
            'earned_minor' => (int) ($earned[$a->project_id] ?? 0), 'service' => $a->project?->service_id !== null,
            'started_at' => $a->activated_at?->toIso8601String(), 'completed_at' => $a->completed_at?->toIso8601String(),
        ])->all();
    }

    /** @return list<array<string, mixed>> the offers still waiting on this client and the invitations still waiting on the freelancer */
    private function leadsOf(Freelancer $me, int $clientId): array
    {
        $offers = DB::table('freelance_offers as o')->join('freelance_projects as p', 'p.id', '=', 'o.project_id')->where('o.freelancer_id', $me->id)->where('p.client_id', $clientId)
            ->whereIn('o.status', ['submitted', 'viewed', 'shortlisted'])->whereNull('p.service_id')->orderByDesc('o.id')->limit(10)->get(['p.uuid', 'p.title', 'o.status', 'o.created_at']);
        $invites = DB::table('freelance_project_invitations as i')->join('freelance_projects as p', 'p.id', '=', 'i.project_id')->where('i.freelancer_id', $me->id)->where('i.client_id', $clientId)
            ->where('i.status', 'sent')->orderByDesc('i.id')->limit(10)->get(['p.uuid', 'p.title', 'i.created_at']);

        return $offers->map(fn ($o) => ['kind' => 'offer', 'project' => $o->uuid, 'title' => $o->title, 'status' => $o->status, 'at' => Carbon::parse($o->created_at)->toIso8601String()])
            ->concat($invites->map(fn ($i) => ['kind' => 'invitation', 'project' => $i->uuid, 'title' => $i->title, 'status' => 'pending', 'at' => Carbon::parse($i->created_at)->toIso8601String()]))->values()->all();
    }
}
