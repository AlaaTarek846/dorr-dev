<?php

namespace Modules\Freelance\Services\Reports;

use App\Models\Country;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as Status;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceDispute;
use Modules\Freelance\Models\FreelanceEarning;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\FreelanceReceipt;
use Modules\Freelance\Services\Policy\FreelanceLedger;
use Modules\Freelance\Services\Projects\MilestonePayments;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Models\WalletHold;
use Modules\Wallet\Models\WalletTransaction;

/**
 * The project money for staff (FL-512): per country, what is held now, what was funded / paid / given back in the period (from the
 * receipts, FL-511), the freelancers' earnings waiting their days, what DORR kept (the ledger), and how many projects; and the
 * reconciliation — the same money counted from two places, listing every difference. Nothing is ever fixed by itself: a difference is
 * shown (and staff told, at most once a day) for a person to look at.
 */
class FreelanceMoneyReport
{
    /** Parts whose money should be held right now. */
    private const HOLDING = [Status::Funded, Status::InProgress, Status::Delivered, Status::Revision, Status::Disputed];

    /** @return list<array<string, mixed>> one row per country with freelance money */
    public function summary(?int $countryId, Carbon $from, Carbon $to): array
    {
        $countries = Country::query()->with('currency')
            ->when($countryId !== null, fn ($q) => $q->whereKey($countryId))
            ->whereIn('id', DB::table('freelance_projects')->select('country_id')->distinct())
            ->orderBy('id')->get();

        return $countries->map(fn (Country $c) => $this->forCountry($c, $from, $to))->values()->all();
    }

    /** @return array<string, mixed> */
    private function forCountry(Country $c, Carbon $from, Carbon $to): array
    {
        $receipts = fn (string $party, string $kind) => FreelanceReceipt::query()->where('country_id', $c->id)->where('party', $party)->where('kind', $kind)
            ->whereBetween('created_at', [$from, $to]);
        $milestoneIds = fn () => Milestone::query()->select('freelance_milestones.id')->join('freelance_projects', 'freelance_projects.id', '=', 'freelance_milestones.project_id')
            ->where('freelance_projects.country_id', $c->id);
        $income = fn (string $slug) => (int) FinancialEntry::query()->where('country_id', $c->id)->whereBetween('entry_date', [$from, $to])
            ->whereHas('category', fn ($q) => $q->where('slug', $slug))->sum('amount_minor');
        $projects = DB::table('freelance_projects')->where('country_id', $c->id);

        return [
            'country' => ['id' => $c->id, 'code' => $c->code],
            'currency' => $c->currency?->code,
            // Now
            'held_minor' => (int) WalletHold::query()->where('reference_type', MilestonePayments::REFERENCE)->where('status', WalletHoldStatus::Active->value)
                ->whereIn('reference_id', $milestoneIds())->sum('amount_minor'),
            'pending_earnings_minor' => (int) FreelanceEarning::query()->where('country_id', $c->id)->where('status', FreelanceEarning::PENDING)->sum('amount_minor'),
            // In the period
            'funded_minor' => (int) $receipts('client', 'funded')->sum('total_minor'),
            'paid_minor' => (int) $receipts('client', 'paid')->sum('total_minor') + (int) $receipts('client', 'settled')->sum('total_minor'),
            'refunded_minor' => (int) $receipts('client', 'refunded')->sum('total_minor') + (int) $receipts('client', 'settled')->sum('refunded_minor'),
            'earned_minor' => (int) $receipts('freelancer', 'earned')->sum('total_minor'),
            'client_fees_minor' => $income(FreelanceLedger::CLIENT_FEES),
            'commissions_minor' => $income(FreelanceLedger::COMMISSIONS),
            'counts' => [
                'projects' => (clone $projects)->whereBetween('created_at', [$from, $to])->count(),
                'agreements_active' => FreelanceAgreement::query()->where('status', FreelanceAgreement::ACTIVE)->whereIn('project_id', (clone $projects)->select('id'))->count(),
                'agreements_completed' => FreelanceAgreement::query()->where('status', FreelanceAgreement::COMPLETED)->whereBetween('completed_at', [$from, $to])
                    ->whereIn('project_id', (clone $projects)->select('id'))->count(),
                'disputes_open' => FreelanceDispute::query()->whereIn('status', [FreelanceDispute::OPEN, FreelanceDispute::DECIDED, FreelanceDispute::APPEALED])
                    ->whereIn('project_id', (clone $projects)->select('id'))->count(),
            ],
        ];
    }

    /**
     * Every difference found, newest parts first. Kinds:
     *  - hold_mismatch       a part that should hold its amount + fee holds something else;
     *  - hold_left           a part that is settled (or not funded) still has money held;
     *  - receipt_missing     a part paid / funded / given back has no receipt for it;
     *  - fee_mismatch        the client fees in the ledger aren't the fees on the receipts;
     *  - commission_mismatch the commissions in the ledger aren't the commissions on the receipts;
     *  - earning_unpaid      an earning released without its wallet credit, or of another amount.
     *
     * @return list<array<string, mixed>>
     */
    public function reconcile(?int $countryId = null): array
    {
        $issues = [];
        $holds = WalletHold::query()->where('reference_type', MilestonePayments::REFERENCE)->where('status', WalletHoldStatus::Active->value)
            ->selectRaw('reference_id, SUM(amount_minor) as held')->groupBy('reference_id')->pluck('held', 'reference_id');

        Milestone::query()->with('project:id,uuid,title,country_id')
            ->when($countryId !== null, fn ($q) => $q->whereHas('project', fn ($p) => $p->where('country_id', $countryId)))
            ->where(fn ($q) => $q->whereIn('status', array_map(fn (Status $s) => $s->value, self::HOLDING))->orWhereIn('id', $holds->keys()))
            ->orderByDesc('id')->get()
            ->each(function (Milestone $m) use ($holds, &$issues): void {
                $held = (int) ($holds[$m->id] ?? 0);
                $should = in_array($m->status, self::HOLDING, true) ? $m->amount_minor + $m->client_fee_minor : 0;
                if ($held !== $should) {
                    $issues[] = $this->issue($should === 0 ? 'hold_left' : 'hold_mismatch', $m, ['expected_minor' => $should, 'found_minor' => $held]);
                }
            });

        // Receipts: every part that moved money since receipts exist has its receipt.
        $since = FreelanceReceipt::query()->min('created_at');
        if ($since !== null) {
            $kinds = FreelanceReceipt::query()->get(['milestone_id', 'party', 'kind'])->groupBy('milestone_id')
                ->map(fn ($rows) => $rows->map(fn ($r) => $r->party.':'.$r->kind)->all());
            Milestone::query()->with('project:id,uuid,title,country_id')
                ->when($countryId !== null, fn ($q) => $q->whereHas('project', fn ($p) => $p->where('country_id', $countryId)))
                ->where(fn ($q) => $q->where('funded_at', '>=', $since)->orWhere('released_at', '>=', $since))
                ->orderByDesc('id')->get()
                ->each(function (Milestone $m) use ($kinds, $since, &$issues): void {
                    $have = $kinds[$m->id] ?? [];
                    // Parts funded before receipts existed have none to ask for.
                    $need = $m->funded_at !== null && $m->funded_at->gte(Carbon::parse($since)) ? ['client:funded'] : [];
                    if ($m->status === Status::Approved) {
                        $need = [...$need, 'client:paid', 'freelancer:earned'];
                    } elseif ($m->status === Status::Cancelled && $m->funded_at !== null && (int) $m->refunded_minor > 0) {
                        $need[] = (int) $m->paid_minor > 0 || in_array('client:settled', $have, true) ? 'client:settled' : 'client:refunded';
                    }
                    $missing = array_values(array_diff($need, $have));
                    if ($missing !== []) {
                        $issues[] = $this->issue('receipt_missing', $m, ['missing' => $missing]);
                    }
                });

            // The ledger against the receipts, per country.
            Country::query()->when($countryId !== null, fn ($q) => $q->whereKey($countryId))
                ->whereIn('id', FreelanceReceipt::query()->select('country_id')->distinct())->get()
                ->each(function (Country $c) use ($since, &$issues): void {
                    $morph = (new Milestone)->getMorphClass();
                    $ledger = fn (string $slug) => (int) FinancialEntry::query()->where('country_id', $c->id)->where('reference_type', $morph)
                        ->whereIn('reference_id', FreelanceReceipt::query()->select('milestone_id')->where('country_id', $c->id))
                        ->whereHas('category', fn ($q) => $q->where('slug', $slug))->sum('amount_minor');
                    $fees = (int) FreelanceReceipt::query()->where('country_id', $c->id)->where('party', 'client')->whereIn('kind', ['paid', 'settled'])->sum('fee_minor');
                    $commissions = (int) FreelanceReceipt::query()->where('country_id', $c->id)->where('party', 'freelancer')->where('kind', 'earned')->sum('fee_minor');
                    foreach ([['fee_mismatch', $ledger(FreelanceLedger::CLIENT_FEES), $fees], ['commission_mismatch', $ledger(FreelanceLedger::COMMISSIONS), $commissions]] as [$kind, $found, $expected]) {
                        if ($found !== $expected) {
                            $issues[] = ['kind' => $kind, 'country' => $c->code, 'expected_minor' => $expected, 'found_minor' => $found];
                        }
                    }
                });
        }

        // Earnings released: each with its wallet credit, of the same amount.
        FreelanceEarning::query()->where('status', FreelanceEarning::RELEASED)
            ->when($countryId !== null, fn ($q) => $q->where('country_id', $countryId))
            ->with('milestone.project:id,uuid,title,country_id')->orderByDesc('id')->get()
            ->each(function (FreelanceEarning $e) use (&$issues): void {
                $tx = $e->wallet_transaction_id === null ? null : WalletTransaction::query()->find($e->wallet_transaction_id);
                if ($tx === null || (int) $tx->amount_minor !== (int) $e->amount_minor) {
                    $issues[] = $this->issue('earning_unpaid', $e->milestone, ['expected_minor' => (int) $e->amount_minor, 'found_minor' => (int) ($tx?->amount_minor ?? 0)]);
                }
            });

        return $issues;
    }

    /** @param  array<string, mixed>  $extra */
    private function issue(string $kind, ?Milestone $m, array $extra): array
    {
        return ['kind' => $kind, 'milestone' => $m?->uuid, 'title' => $m?->title, 'status' => $m?->status?->value,
            'project' => $m?->project === null ? null : ['uuid' => $m->project->uuid, 'title' => $m->project->title]] + $extra;
    }
}
