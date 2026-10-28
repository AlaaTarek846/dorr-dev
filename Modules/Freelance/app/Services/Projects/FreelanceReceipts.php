<?php

namespace Modules\Freelance\Services\Projects;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\FreelanceReceipt as Receipt;
use Modules\Freelance\Models\Freelancer;

/**
 * Receipts (FL-511): each money movement of a part leaves one for the side it concerns, numbered in order per country and year
 * (FR-SA-2026-000123) and written inside the movement's own transaction — so there is never a movement without its receipt, nor
 * a receipt for one that was rolled back.
 *
 *  - funded   (client)     the part's amount and service fee held from the wallet;
 *  - paid     (client)     the held money paid out on approval (by the client, by itself after the review days, or a dispute);
 *  - earned   (freelancer) the amount, the commission and the net — on approval, or the share when a started part ends;
 *  - refunded (client)     everything held given back;
 *  - settled  (client)     a started part ended: what was paid for the share and what came back.
 */
class FreelanceReceipts
{
    public const PREFIX = 'FR';

    /** @param  array{amount?: int, fee?: int, total?: int, refunded?: int}  $money */
    public function issue(Milestone $m, Agreement $a, string $party, string $kind, ?string $reason, array $money): ?Receipt
    {
        if (($money['total'] ?? 0) <= 0 && ($money['refunded'] ?? 0) <= 0) {
            return null;
        }
        // The same movement twice (a retry) keeps its first receipt.
        $existing = Receipt::query()->where('milestone_id', $m->id)->where('party', $party)->where('kind', $kind)->first();
        if ($existing !== null) {
            return $existing;
        }
        $a->loadMissing('project');
        $countryId = (int) $a->project->country_id;
        $year = (int) now()->year;
        $seq = $this->next($countryId, $year);
        $code = strtoupper((string) Country::query()->whereKey($countryId)->value('code'));

        return Receipt::query()->create([
            'number' => sprintf('%s-%s-%d-%06d', self::PREFIX, $code, $year, $seq),
            'country_id' => $countryId, 'year' => $year, 'seq' => $seq,
            'party' => $party, 'kind' => $kind, 'reason' => $reason,
            'project_id' => $a->project_id, 'agreement_id' => $a->id, 'milestone_id' => $m->id,
            'client_id' => $a->client_id, 'freelancer_id' => $a->freelancer_id, 'currency_code' => $a->currency_code,
            'amount_minor' => (int) ($money['amount'] ?? 0), 'fee_minor' => (int) ($money['fee'] ?? 0),
            'total_minor' => (int) ($money['total'] ?? 0), 'refunded_minor' => (int) ($money['refunded'] ?? 0),
        ]);
    }

    /**
     * @param  'client'|'freelancer'  $side
     * @return array{data: list<array<string, mixed>>}
     */
    public function list(Model $owner, string $side, int $limit = 50): array
    {
        $rows = Receipt::query()->where($side === 'client' ? 'client_id' : 'freelancer_id', $owner->getKey())->where('party', $side)
            ->with('project:id,uuid,title', 'milestone:id,uuid,title')->orderByDesc('id')->limit($limit)->get();

        return ['data' => $rows->map(fn (Receipt $r) => $this->present($r, false))->all()];
    }

    public function find(Model $owner, string $side, string $uuid): Receipt
    {
        return Receipt::query()->where('uuid', $uuid)->where($side === 'client' ? 'client_id' : 'freelancer_id', $owner->getKey())->where('party', $side)
            ->with('project:id,uuid,title', 'milestone:id,uuid,title', 'client:id,name', 'freelancer:id,name')->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function present(Receipt $r, bool $full = true): array
    {
        $out = [
            'uuid' => $r->uuid, 'number' => $r->number, 'party' => $r->party, 'kind' => $r->kind, 'reason' => $r->reason,
            'title' => __('freelance.receipts.kinds.'.$r->kind),
            'project' => ['uuid' => $r->project?->uuid, 'title' => $r->project?->title],
            'milestone' => ['uuid' => $r->milestone?->uuid, 'title' => $r->milestone?->title],
            'currency' => $r->currency_code, 'amount_minor' => $r->amount_minor, 'fee_minor' => $r->fee_minor,
            'total_minor' => $r->total_minor, 'refunded_minor' => $r->refunded_minor,
            'issued_at' => $r->created_at?->toIso8601String(),
        ];
        if ($full) {
            $out += [
                'client' => $r->client?->name, 'freelancer' => $r->freelancer instanceof Freelancer ? $r->freelancer->name : null,
                'reason_text' => $r->reason === null ? null : __('freelance.receipts.reasons.'.$r->reason),
                'text' => $this->text($r),
            ];
        }

        return $out;
    }

    /** The receipt as plain text, for sharing from the apps. */
    public function text(Receipt $r): string
    {
        $m = fn (int $minor) => number_format($minor / 100, 2).' '.$r->currency_code;
        $lines = [
            __('freelance.receipts.brand'),
            __('freelance.receipts.kinds.'.$r->kind).' — '.$r->number,
            __('freelance.receipts.lines.date').': '.$r->created_at?->format('Y-m-d H:i'),
            __('freelance.receipts.lines.project').': '.$r->project?->title,
            __('freelance.receipts.lines.milestone').': '.$r->milestone?->title,
        ];
        if ($r->amount_minor > 0) {
            $lines[] = __('freelance.receipts.lines.amount').': '.$m($r->amount_minor);
        }
        if ($r->fee_minor > 0) {
            $lines[] = __('freelance.receipts.lines.'.($r->party === 'client' ? 'client_fee' : 'commission')).': '.$m($r->fee_minor);
        }
        if ($r->total_minor > 0) {
            $lines[] = __('freelance.receipts.lines.total_'.$r->kind).': '.$m($r->total_minor);
        }
        if ($r->refunded_minor > 0) {
            $lines[] = __('freelance.receipts.lines.refunded').': '.$m($r->refunded_minor);
        }
        if ($r->reason !== null) {
            $lines[] = __('freelance.receipts.lines.reason').': '.__('freelance.receipts.reasons.'.$r->reason);
        }

        return implode("\n", $lines);
    }

    /** The next number of the country's year, under a lock on its counter. */
    private function next(int $countryId, int $year): int
    {
        DB::table('freelance_receipt_counters')->insertOrIgnore(['country_id' => $countryId, 'year' => $year, 'last_seq' => 0]);
        $row = DB::table('freelance_receipt_counters')->where('country_id', $countryId)->where('year', $year)->lockForUpdate()->first();
        $seq = (int) $row->last_seq + 1;
        DB::table('freelance_receipt_counters')->where('country_id', $countryId)->where('year', $year)->update(['last_seq' => $seq]);

        return $seq;
    }
}
