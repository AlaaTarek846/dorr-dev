<?php

namespace Modules\Freelance\Services\Rights;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceRightsClaim as Claim;
use Modules\Freelance\Models\FreelanceService as Service;
use Modules\Freelance\Models\FreelancerPortfolioItem as PortfolioItem;
use Modules\Freelance\Services\Account\FreelancerPortfolio;
use Modules\Freelance\Services\Market\FreelancerServices;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\User\Models\User;

/**
 * Reports that a work shown on Dorr belongs to someone else (FL-L13): a portfolio item or a ready-made service, reported by a signed-in
 * client or by anyone outside (a rights holder who found it on the website). The staff decide: upheld takes the work down (a portfolio
 * item is hidden, a service suspended — the owner is told by the usual notice) and dismissed leaves it. Only works that others can see
 * can be reported; the same person does not have two open reports on one work.
 */
class RightsClaims
{
    public function __construct(
        private readonly FreelancerPortfolio $portfolio,
        private readonly FreelancerServices $services,
        private readonly FreelanceNotifier $notifier,
    ) {}

    /**
     * @param  'portfolio_item'|'service'  $type
     * @param  array{name?: ?string, contact?: ?string}  $external  who reports when nobody is signed in
     */
    public function report(string $type, string $uuid, string $details, ?User $client, array $external = []): Claim
    {
        [$subject, $title, $freelancerId] = $this->subject($type, $uuid);
        $details = mb_substr(trim(strip_tags($details)), 0, 2000);
        $reporter = $client !== null
            ? ['reporter_type' => 'client', 'reporter_id' => $client->id, 'reporter_name' => $client->name, 'reporter_contact' => null]
            : ['reporter_type' => 'external', 'reporter_id' => null,
                'reporter_name' => mb_substr(trim(strip_tags((string) ($external['name'] ?? ''))), 0, 120) ?: null,
                'reporter_contact' => mb_substr(trim(strip_tags((string) ($external['contact'] ?? ''))), 0, 160) ?: null];

        return DB::transaction(function () use ($type, $subject, $title, $freelancerId, $details, $reporter): Claim {
            $same = Claim::query()->where('subject_type', $type)->where('subject_id', $subject->getKey())->where('status', Claim::OPEN)
                ->where('reporter_type', $reporter['reporter_type'])
                ->when($reporter['reporter_type'] === 'client', fn ($q) => $q->where('reporter_id', $reporter['reporter_id']), fn ($q) => $q->where('reporter_contact', $reporter['reporter_contact']))
                ->lockForUpdate()->exists();
            if ($same) {
                throw new FreelanceException('rights_claim_exists', 409);
            }

            return Claim::query()->create($reporter + [
                'subject_type' => $type, 'subject_id' => $subject->getKey(), 'subject_title' => mb_substr($title, 0, 160), 'freelancer_id' => $freelancerId,
                'details' => $details, 'status' => Claim::OPEN,
            ]);
        });
    }

    /** The staff decide. `uphold` takes the work down, `dismiss` leaves it. */
    public function decide(Claim $claim, string $decision, string $note, int $adminId): Claim
    {
        $decided = DB::transaction(function () use ($claim, $decision, $note, $adminId): Claim {
            $c = Claim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== Claim::OPEN) {
                throw new FreelanceException('rights_claim_closed', 409);
            }
            $note = mb_substr(trim(strip_tags($note)), 0, 1000);
            if ($decision === 'uphold') {
                $this->takeDown($c, $note, $adminId);
            }
            $c->fill(['status' => $decision === 'uphold' ? Claim::UPHELD : Claim::DISMISSED, 'decision_note' => $note ?: null, 'decided_by_admin_id' => $adminId, 'decided_at' => now()])->save();

            return $c;
        });
        // A signed-in reporter is told how it ended.
        if ($decided->reporter_type === 'client' && ($client = User::query()->find($decided->reporter_id)) !== null) {
            $this->notifier->notify($client, 'rights.claim_closed', ['title' => $decided->subject_title, 'outcome' => __('freelance.rights.claim_outcomes.'.$decided->status)], ['rights_claim' => $decided->uuid]);
        }

        return $decided;
    }

    /** @return LengthAwarePaginator<Claim> */
    public function page(?string $status, int $perPage): LengthAwarePaginator
    {
        return Claim::query()->with('freelancer')->when($status !== null, fn ($q) => $q->where('status', $status))
            // What waits for a decision first, the oldest at the top.
            ->orderByRaw("case status when 'open' then 0 else 1 end")->orderBy('id')->paginate($perPage);
    }

    /** @return array<string, mixed> */
    public function present(Claim $c): array
    {
        return [
            'uuid' => $c->uuid, 'status' => $c->status, 'subject_type' => $c->subject_type, 'subject_title' => $c->subject_title,
            'freelancer' => $c->freelancer === null ? null : ['id' => $c->freelancer->id, 'name' => $c->freelancer->name],
            'reporter' => ['type' => $c->reporter_type, 'name' => $c->reporter_name, 'contact' => $c->reporter_contact, 'id' => $c->reporter_id],
            'details' => $c->details, 'decision_note' => $c->decision_note, 'decided_at' => $c->decided_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------- inside

    /** @return array{0: PortfolioItem|Service, 1: string, 2: ?int} the work, what it is called, and whose it is */
    private function subject(string $type, string $uuid): array
    {
        if ($type === Claim::PORTFOLIO_ITEM) {
            $item = PortfolioItem::query()->where('uuid', $uuid)->with('freelancer')->first();
            if ($item === null || $item->isHidden() || $item->withdrawnByClient() || $item->visibility === PortfolioItem::PRIVATE || $item->freelancer?->status !== FreelancerStatus::Verified) {
                throw new FreelanceException('rights_claim_subject_not_found', 404);
            }

            return [$item, (string) $item->title, (int) $item->freelancer_id];
        }
        $service = Service::query()->where('uuid', $uuid)->where('status', Service::PUBLISHED)->first();
        if ($service === null) {
            throw new FreelanceException('rights_claim_subject_not_found', 404);
        }

        return [$service, (string) $service->title, (int) $service->freelancer_id];
    }

    private function takeDown(Claim $c, string $note, int $adminId): void
    {
        $reason = $note !== '' ? $note : __('freelance.dispute_reasons.rights');
        if ($c->subject_type === Claim::PORTFOLIO_ITEM) {
            $item = PortfolioItem::query()->find($c->subject_id);
            if ($item !== null && ! $item->isHidden()) {
                $this->portfolio->hide($item, $reason, $adminId);
            }

            return;
        }
        $service = Service::query()->find($c->subject_id);
        if ($service !== null && $service->status !== Service::SUSPENDED) {
            $this->services->suspend($service, $reason, $adminId);
        }
    }
}
