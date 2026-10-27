<?php

namespace Modules\Freelance\Services\Projects;

use Illuminate\Support\Collection;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelanceFees;

/**
 * The parts an agreement is paid in (FL-402). Every agreement has one at least: made with the draft as one part for the whole price,
 * and the client may split it while the agreement can still change. The parts add up to the price exactly; how many and how small
 * comes from the policy frozen in the agreement (`milestones.max_count`, `milestones.min_amount_minor` — the minimum only binds when
 * there is more than one part). Each part keeps its own client fee, commission and net, worked out from that same policy.
 */
class Milestones
{
    public function __construct(private readonly FreelanceFeatures $features) {}

    /** One part for the whole price (with the draft). */
    public function single(Agreement $a): void
    {
        $this->write($a, [['title' => __('freelance.milestone_default'), 'description' => null, 'amount_minor' => (int) $a->price_minor, 'duration_days' => $a->duration_days]]);
    }

    /**
     * Replaces the parts (the caller checked the agreement can change).
     *
     * @param  list<array{title: string, description?: ?string, amount_minor: int, duration_days?: ?int}>  $items
     */
    public function replace(Agreement $a, array $items): void
    {
        $policy = (array) $a->policy;
        $max = max(1, (int) ($policy['milestones.max_count'] ?? 5));
        if ($items === []) {
            throw new FreelanceException('milestones_required', 422);
        }
        if (count($items) > $max) {
            throw new FreelanceException('milestones_too_many', 422, ['max' => $max], ['max' => $max]);
        }
        $sum = array_sum(array_map(fn ($i) => (int) $i['amount_minor'], $items));
        if ($sum !== (int) $a->price_minor) {
            throw new FreelanceException('milestones_total', 422, ['total' => self::money($a->price_minor, $a->currency_code)], ['total_minor' => (int) $a->price_minor, 'sum_minor' => $sum]);
        }
        $min = $policy['milestones.min_amount_minor'] ?? null;
        if ($min !== null && count($items) > 1) {
            foreach ($items as $i) {
                if ((int) $i['amount_minor'] < (int) $min) {
                    throw new FreelanceException('milestone_too_small', 422, ['min' => self::money((int) $min, $a->currency_code)], ['min_minor' => (int) $min]);
                }
            }
        }
        $this->write($a, $items);
    }

    /** @return Collection<int, Milestone> */
    public function of(Agreement $a): Collection
    {
        return Milestone::query()->where('agreement_id', $a->id)->orderBy('position')->get();
    }

    /** @return array<string, mixed> each side sees its own money */
    public static function present(Milestone $m, string $side, ?int $freeRevisions = null): array
    {
        return [
            'uuid' => $m->uuid,
            'position' => $m->position,
            'title' => $m->title,
            'description' => $m->description,
            'amount_minor' => $m->amount_minor,
            'duration_days' => $m->duration_days,
            'status' => $m->status->value,
            // FL-403: the work on it.
            'delivery_note' => $m->delivery_note,
            'revision_reason' => $m->status === \Modules\Freelance\Enums\FreelanceMilestoneStatus::Revision ? $m->revision_reason : null,
            'revisions_used' => $m->revisions_used,
            // FL-407: the free rounds of changes.
            'revisions_max' => $freeRevisions,
            'revisions_left' => $freeRevisions === null ? null : max(0, $freeRevisions - $m->revisions_used),
            // FL-506: who asked to end it (while the other side can still answer), what went back to the client.
            'cancel_requested_by' => app(MilestoneCancellation::class)->open($m) ? $m->cancel_requested_by : null,
            'refunded_minor' => $m->refunded_minor,
            // FL-704: its dispute (the current one, else the last).
            'dispute' => ($d = Disputes::ofMilestone($m)) === null ? null : ['uuid' => $d->uuid, 'status' => $d->status],
            // FL-601 / FL-604: how many versions were delivered, and what the client checks before approving.
            'deliveries_count' => app(MilestoneDeliveries::class)->count($m),
            'checklist' => app(MilestoneDeliveries::class)->checks($m),
            'delivered_at' => $m->delivered_at?->toIso8601String(),
            'approved_at' => $m->approved_at?->toIso8601String(),
            // FL-503: paid out.
            'released_at' => $m->released_at?->toIso8601String(),
        ] + ($side === 'client'
            ? ['client_fee_minor' => $m->client_fee_minor, 'total_minor' => $m->amount_minor + $m->client_fee_minor]
            : ['commission_minor' => $m->commission_minor, 'net_minor' => $m->net_minor, 'paid_minor' => $m->paid_minor]);
    }

    /**
     * A part's money for an amount: its client fee, commission and net, from the policy frozen in the agreement (and the switches the
     * offer was priced with). Also for a part changed or added after the agreement (FL-405).
     *
     * @return array{amount_minor: int, client_fee_minor: int, client_total_minor: int, commission_minor: int, freelancer_net_minor: int}
     */
    public function numbers(Agreement $a, int $amount): array
    {
        return FreelanceFees::breakdown($amount, $this->values($a));
    }

    /** @return array<string, int> the agreement's money as the sum of its parts (each worked out on its own) */
    public function totals(Agreement $a): array
    {
        $parts = $this->of($a);
        $price = (int) $parts->sum('amount_minor');
        $fee = (int) $parts->sum('client_fee_minor');

        return ['price_minor' => $price, 'client_fee_minor' => $fee, 'client_total_minor' => $price + $fee,
            'commission_minor' => (int) $parts->sum('commission_minor'), 'net_minor' => (int) $parts->sum('net_minor')];
    }

    /** @return array<string, mixed> */
    private function values(Agreement $a): array
    {
        $values = (array) $a->policy;
        $countryId = (int) $a->project?->country_id;
        // The switches as the offer was priced: where the fee or the commission is off, nothing is taken.
        if (! $this->features->allows($countryId, 'client_fee')) {
            $values['client_fee.type'] = 'fixed';
            $values['client_fee.value'] = 0;
            $values['client_fee.min_minor'] = null;
        }
        if (! $this->features->allows($countryId, 'commission')) {
            $values['commission.type'] = 'fixed';
            $values['commission.value'] = 0;
            $values['commission.min_minor'] = null;
        }

        return $values;
    }

    /** @param  list<array<string, mixed>>  $items */
    private function write(Agreement $a, array $items): void
    {
        Milestone::query()->where('agreement_id', $a->id)->delete();
        foreach (array_values($items) as $n => $i) {
            $b = $this->numbers($a, (int) $i['amount_minor']);
            Milestone::query()->create([
                'agreement_id' => $a->id,
                'project_id' => $a->project_id,
                'position' => $n + 1,
                'title' => mb_substr(trim(strip_tags((string) $i['title'])), 0, 120),
                'description' => ($d = trim(strip_tags((string) ($i['description'] ?? '')))) === '' ? null : mb_substr($d, 0, 500),
                'amount_minor' => $b['amount_minor'],
                'duration_days' => isset($i['duration_days']) ? (int) $i['duration_days'] : null,
                'client_fee_minor' => $b['client_fee_minor'],
                'commission_minor' => $b['commission_minor'],
                'net_minor' => $b['freelancer_net_minor'],
                'status' => \Modules\Freelance\Enums\FreelanceMilestoneStatus::Unfunded,
            ]);
        }
    }

    private static function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2).' '.$currency;
    }
}
