<?php

namespace Modules\Freelance\Services\Offers;

use Illuminate\Support\Collection;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceTalentList;
use Modules\Freelance\Models\FreelanceTalentListItem as Item;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Account\FreelancerCompleteness;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Projects\ProjectInvitations;
use Modules\User\Models\User;

/**
 * FS-103: a client's own lists of freelancers to work with again - named, optionally for one specialty - saved from a profile or an offer,
 * and used to invite people to a private project in one go. Only the client sees his lists; a freelancer learns only how many lists he is in
 * (no name, no client). A reminder to hire again exists only when the client asks for it on that person.
 */
class TalentPool
{
    public const MAX_LISTS = 20;

    public const MAX_PER_LIST = 100;

    /** How soon a "hire again" reminder can be set. */
    public const REMIND_DAYS = [30, 60, 90, 180];

    public function __construct(private readonly ProjectInvitations $invitations, private readonly FreelanceNotifier $notifier, private readonly FreelancerCompleteness $completeness) {}

    /** @return Collection<int, array<string, mixed>> newest first */
    public function lists(User $client, ?int $specialtyId = null): Collection
    {
        return FreelanceTalentList::query()->where('user_id', $client->id)->withCount('items')->with('specialty.translations')
            ->when($specialtyId !== null, fn ($q) => $q->where('freelance_specialty_id', $specialtyId))
            ->latest('id')->get()->map(fn (FreelanceTalentList $l) => $this->presentList($l));
    }

    public function find(User $client, string $uuid): FreelanceTalentList
    {
        return FreelanceTalentList::query()->where('user_id', $client->id)->where('uuid', $uuid)->with('specialty.translations')->firstOrFail();
    }

    public function create(User $client, string $name, ?int $specialtyId): FreelanceTalentList
    {
        if (FreelanceTalentList::query()->where('user_id', $client->id)->count() >= self::MAX_LISTS) {
            throw new FreelanceException('talent_lists_limit', 422, ['max' => self::MAX_LISTS], ['max' => self::MAX_LISTS]);
        }

        return FreelanceTalentList::query()->create(['user_id' => $client->id, 'name' => $name, 'freelance_specialty_id' => $specialtyId])->load('specialty.translations');
    }

    public function update(FreelanceTalentList $list, string $name, ?int $specialtyId): FreelanceTalentList
    {
        $list->forceFill(['name' => $name, 'freelance_specialty_id' => $specialtyId])->save();

        return $list->load('specialty.translations');
    }

    public function delete(FreelanceTalentList $list): void
    {
        $list->delete();
    }

    /** A freelancer a client may keep: a verified one (the client only has his uuid from an offer, a profile or the catalog). */
    public function freelancer(string $uuid): Freelancer
    {
        return Freelancer::query()->where('uuid', $uuid)->where('status', FreelancerStatus::Verified->value)->firstOrFail();
    }

    public function add(FreelanceTalentList $list, Freelancer $freelancer, ?string $note): Item
    {
        $item = Item::query()->where('list_id', $list->id)->where('freelancer_id', $freelancer->id)->first();
        if ($item === null) {
            if (Item::query()->where('list_id', $list->id)->count() >= self::MAX_PER_LIST) {
                throw new FreelanceException('talent_list_full', 422, ['max' => self::MAX_PER_LIST], ['max' => self::MAX_PER_LIST]);
            }
            $item = Item::query()->create(['list_id' => $list->id, 'freelancer_id' => $freelancer->id, 'note' => $note]);
        } elseif ($note !== null) {
            $item->forceFill(['note' => $note])->save();
        }

        return $item;
    }

    public function remove(FreelanceTalentList $list, Freelancer $freelancer): void
    {
        Item::query()->where('list_id', $list->id)->where('freelancer_id', $freelancer->id)->delete();
    }

    /**
     * Changes what the client keeps about a person: his note and the reminder (null days: no reminder). The reminder is the client's own
     * choice, one per person per list, and it goes to the client only.
     *
     * @param  array{note?: ?string, remind_in_days?: ?int}  $fields
     */
    public function updateMember(FreelanceTalentList $list, Freelancer $freelancer, array $fields): Item
    {
        $item = Item::query()->where('list_id', $list->id)->where('freelancer_id', $freelancer->id)->firstOrFail();
        if (array_key_exists('note', $fields)) {
            $item->note = $fields['note'];
        }
        if (array_key_exists('remind_in_days', $fields)) {
            $item->remind_at = $fields['remind_in_days'] === null ? null : now()->addDays((int) $fields['remind_in_days']);
            $item->reminded_at = null;
        }
        $item->save();

        return $item;
    }

    /** @return array<string, mixed> the list and the people in it, newest first */
    public function show(User $client, FreelanceTalentList $list): array
    {
        $items = Item::query()->where('list_id', $list->id)->with(['freelancer.media'])->latest('id')->get();
        $worked = FreelanceAgreement::query()->where('client_id', $client->id)->where('status', FreelanceAgreement::COMPLETED)
            ->whereIn('freelancer_id', $items->pluck('freelancer_id'))->pluck('freelancer_id')->flip();

        return $this->presentList($list->loadCount('items')) + [
            'members' => $items->filter(fn (Item $i) => $i->freelancer !== null)->map(fn (Item $i) => [
                'uuid' => $i->freelancer->uuid,
                'name' => $i->freelancer->name,
                'headline' => $i->freelancer->headline,
                'avatar_url' => $i->freelancer->getFirstMediaUrl('avatar') ?: null,
                'verified' => $i->freelancer->status === FreelancerStatus::Verified,
                'completeness' => $this->completeness->of($i->freelancer)['percent'],
                'note' => $i->note,
                'remind_at' => $i->remind_at?->toIso8601String(),
                'worked_together' => $worked->has($i->freelancer_id),
                'added_at' => $i->created_at?->toIso8601String(),
            ])->values()->all(),
            'remind_options' => self::REMIND_DAYS,
        ];
    }

    /** @return list<string> the uuids of the client's lists a freelancer is in (the heart on a profile) */
    public function listsHolding(User $client, Freelancer $freelancer): array
    {
        return FreelanceTalentList::query()->where('user_id', $client->id)
            ->whereIn('id', Item::query()->where('freelancer_id', $freelancer->id)->select('list_id'))->pluck('uuid')->all();
    }

    /**
     * Asks the people of a list (or some of them) to offer on one of the client's private projects - the usual invitation, so the same
     * rules and limits apply.
     *
     * @param  list<string>|null  $only  uuids inside the list; null: everyone in it
     * @return array{invited: int, skipped: list<array{uuid: string, reason: string}>}
     */
    public function invite(User $client, FreelanceTalentList $list, string $projectUuid, ?array $only): array
    {
        $project = FreelanceProject::query()->where('client_id', $client->id)->where('uuid', $projectUuid)->firstOrFail();
        $uuids = Item::query()->where('list_id', $list->id)->join('freelancers', 'freelancers.id', '=', 'freelance_talent_list_items.freelancer_id')
            ->when($only !== null, fn ($q) => $q->whereIn('freelancers.uuid', $only))->pluck('freelancers.uuid')->all();

        return $this->invitations->invite($project, $client, $uuids);
    }

    /** Hourly: the client is told, once, that the time he asked for has come to think of hiring this person again. @return int reminders sent */
    public function remindDue(): int
    {
        $sent = 0;
        Item::query()->whereNotNull('remind_at')->whereNull('reminded_at')->where('remind_at', '<=', now())->with(['list.owner', 'freelancer'])
            ->each(function (Item $i) use (&$sent): void {
                $client = $i->list?->owner;
                $i->forceFill(['reminded_at' => now()])->save();
                if ($client === null || $i->freelancer === null) {
                    return;
                }
                $this->notifier->notify($client, 'talent.reminder', ['name' => (string) $i->freelancer->name], ['list' => $i->list->uuid, 'freelancer' => $i->freelancer->uuid]);
                $sent++;
            });

        return $sent;
    }

    /** How many lists a freelancer is in - the only thing he can see about them. */
    public function countFor(Freelancer $freelancer, $since = null): int
    {
        return Item::query()->where('freelancer_id', $freelancer->id)->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))->count();
    }

    /** @return array<string, mixed> */
    private function presentList(FreelanceTalentList $l): array
    {
        return [
            'uuid' => $l->uuid,
            'name' => $l->name,
            'specialty' => $l->specialty === null ? null : ['id' => $l->specialty->id, 'name' => $l->specialty->translated('name')],
            'count' => (int) ($l->items_count ?? $l->items()->count()),
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }
}
