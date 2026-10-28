<?php

namespace Modules\Rides\Services\Cases;

use App\Models\Country;
use App\Models\Currency;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Admin\Models\Admin;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\CaseKind;
use Modules\Rides\Enums\CaseStatus;
use Modules\Rides\Enums\RidePaymentMethod;
use Modules\Rides\Enums\RidePaymentStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideAdjustment;
use Modules\Rides\Models\RideCase;
use Modules\Rides\Models\RideCaseEvent;
use Modules\Rides\Models\RideCaseFile;
use Modules\Rides\Models\RideCaseMessage;
use Modules\Rides\Models\RidePriceLine;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\FinancialLedgerService;
use Modules\Wallet\Services\WalletService;

/**
 * Complaints, financial disputes and "the rider left without paying" reports (R-133..R-140, D-229..D-238),
 * as cases of their own: opened against a ride the person was on, with the system's evidence frozen at that
 * moment, a conversation and files, an admin's decision with its reason and any refund, and one appeal
 * where the country allows it. A case never changes the ride's status and never moves money by itself
 * (a decision's refund is an admin's explicit act); it is never deleted.
 */
class RideCases
{
    public function __construct(
        private readonly RideFeatures $features,
        private readonly RidePolicies $policies,
        private readonly CaseEvidence $evidence,
        private readonly WalletService $wallets,
        private readonly FinancialLedgerService $ledger,
        private readonly NotificationCenter $center,
    ) {}

    public static function partyType(Model $party): string
    {
        return $party instanceof Driver ? 'driver' : 'user';
    }

    /**
     * What this person can open and pick from (empty when the country hasn't switched cases on).
     *
     * @return array<string, mixed>
     */
    public function options(Model $party): array
    {
        $driver = $party instanceof Driver;
        $available = $this->available($party);
        $labels = fn (array $codes, string $group) => array_map(fn (string $c) => ['code' => $c, 'label' => __("rides.cases.$group.$c")], $codes);

        return [
            'available' => $available,
            'kinds' => ! $available ? [] : array_map(fn (string $k) => ['code' => $k, 'label' => __("rides.cases.kinds.$k")], $driver ? ['dispute', 'unpaid_report'] : ['complaint', 'dispute']),
            'complaint_categories' => $available && ! $driver ? $labels((array) config('rides.cases.complaint_categories'), 'categories') : [],
            'dispute_reasons' => $available ? $labels((array) config('rides.cases.dispute_reasons'), 'reasons') : [],
            'max_files' => (int) config('rides.cases.max_files', 6),
            'max_file_kb' => (int) config('rides.cases.max_file_kb', 5120),
        ];
    }

    /**
     * @param  array{kind: string, ride_uuid?: string|null, category?: string|null, summary: string}  $in
     */
    public function open(Model $party, array $in): RideCase
    {
        if (! $this->available($party)) {
            throw RidesException::featureDisabled('cases');
        }
        $driver = $party instanceof Driver;
        $kind = CaseKind::tryFrom((string) $in['kind']);
        if ($kind === null || ! in_array($kind->value, $driver ? ['dispute', 'unpaid_report'] : ['complaint', 'dispute'], true)) {
            throw new RidesException('case_kind_not_allowed');
        }

        $ride = null;
        if (! empty($in['ride_uuid'])) {
            $ride = Ride::query()->where('uuid', $in['ride_uuid'])->where($driver ? 'driver_id' : 'user_id', $party->getKey())->first()
                ?? throw new RidesException('ride_not_found', 404);
        }
        if ($ride === null && $kind !== CaseKind::Complaint) {
            throw new RidesException('case_needs_ride');
        }

        $category = $kind === CaseKind::UnpaidReport ? 'unpaid' : (string) ($in['category'] ?? '');
        $allowed = match ($kind) {
            CaseKind::Complaint => (array) config('rides.cases.complaint_categories'),
            CaseKind::Dispute => (array) config('rides.cases.dispute_reasons'),
            CaseKind::UnpaidReport => ['unpaid'],
        };
        if (! in_array($category, $allowed, true)) {
            throw new RidesException('case_category_invalid');
        }

        $policy = $ride === null ? $this->policies->for((int) $party->country_id) : $this->policies->for((int) $ride->country_id, $ride->city_id === null ? null : (int) $ride->city_id);
        $disputed = null;
        if ($ride !== null) {
            $maxAge = $policy->int('cases.max_age_h');
            $ended = $ride->completed_at ?? $ride->canceled_at ?? $ride->updated_at;
            if ($maxAge > 0 && $ride->status->isFinal() && $ended !== null && $ended->lt(now()->subHours($maxAge))) {
                throw new RidesException('case_too_late', 422, ['hours' => $maxAge]);
            }
            if ($kind !== CaseKind::Complaint && ! $ride->status->isFinal()) {
                throw new RidesException('case_ride_not_finished');
            }
            if ($kind === CaseKind::Dispute) {
                $disputed = $this->disputedAmount($ride, $category);
                if ($disputed === null || $disputed <= 0) {
                    throw new RidesException('case_nothing_to_dispute');
                }
            }
            if ($kind === CaseKind::UnpaidReport) {
                // Only a cash ride that ended and whose cash the captain never recorded (D-229).
                if ($ride->status !== RideStatus::Completed || $ride->payment_method !== RidePaymentMethod::Cash || $ride->payment_status !== RidePaymentStatus::CashDue) {
                    throw new RidesException('case_not_eligible');
                }
                $disputed = (int) $ride->final_total_minor;
            }
        }

        $same = RideCase::query()->where('reporter_type', self::partyType($party))->where('reporter_id', $party->getKey())
            ->where('kind', $kind->value)->where('category', $category)->whereNotIn('status', array_map(fn (CaseStatus $s) => $s->value, array_filter(CaseStatus::cases(), fn (CaseStatus $s) => $s->isDecided())))
            ->when($ride, fn ($q) => $q->where('ride_id', $ride->id), fn ($q) => $q->whereNull('ride_id'))->exists();
        if ($same) {
            throw new RidesException('case_already_open', 409);
        }

        $case = DB::transaction(function () use ($party, $kind, $ride, $category, $in, $disputed) {
            $case = RideCase::query()->create([
                'uuid' => (string) Str::uuid(),
                'number' => $this->number(),
                'kind' => $kind,
                'reporter_type' => self::partyType($party),
                'reporter_id' => $party->getKey(),
                'ride_id' => $ride?->id,
                'country_id' => $ride?->country_id ?? $party->country_id,
                'category' => $category,
                'priority' => (string) (config('rides.cases.priority')[$category] ?? 'normal'),
                'status' => CaseStatus::Submitted,
                'summary' => trim((string) $in['summary']),
                'disputed_minor' => $disputed,
                'currency_id' => $ride?->currency_id,
                'evidence' => $ride === null ? null : $this->evidence->forRide($ride),
            ]);
            $this->log($case, 'opened', $party, ['kind' => $kind->value, 'category' => $category]);

            return $case;
        });
        // AI-12: DORR AI reads it for the team once the person has their answer (never holds them up).
        $id = $case->id;
        dispatch(function () use ($id) {
            if (($fresh = RideCase::query()->find($id)) !== null) {
                app(CaseTriage::class)->run($fresh);
            }
        })->afterResponse();

        return $case;
    }

    /** @return array<string, mixed> */
    public function present(RideCase $case, string $viewer): array
    {
        $case->loadMissing(['ride', 'messages', 'files', 'events']);
        $admin = $viewer === 'admin';
        $decision = $case->decision;
        $payload = [
            'uuid' => $case->uuid,
            'number' => $case->number,
            'kind' => $case->kind->value,
            'kind_label' => __('rides.cases.kinds.'.$case->kind->value),
            'category' => $case->category,
            'category_label' => $case->kind === CaseKind::UnpaidReport ? __('rides.cases.kinds.unpaid_report') : __('rides.cases.'.($case->kind === CaseKind::Dispute ? 'reasons' : 'categories').'.'.$case->category),
            'status' => $case->status->value,
            'status_label' => __('rides.cases.status.'.$case->status->value),
            'decided' => $case->status->isDecided(),
            'summary' => $case->summary,
            'ride' => $case->ride === null ? null : ['uuid' => $case->ride->uuid, 'number' => $case->ride->number, 'status' => $case->ride->status->value],
            'disputed_minor' => $case->disputed_minor,
            'currency' => $this->currency($case->currency_id),
            'evidence' => $this->evidence->forViewer($case->evidence, $viewer),
            'messages' => $case->messages->filter(fn (RideCaseMessage $m) => $admin || ! $m->internal)->map(fn (RideCaseMessage $m) => [
                'id' => $m->id, 'from' => $m->author_type === 'admin' ? 'support' : ($m->author_type === $viewer ? 'me' : $m->author_type), 'body' => $m->body, 'internal' => $m->internal, 'at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
            'files' => $case->files->map(fn (RideCaseFile $f) => [
                'id' => $f->id, 'name' => $f->name, 'mime' => $f->mime, 'size' => $f->size, 'url' => Storage::disk('public')->url($f->path), 'mine' => $f->uploader_type === $viewer,
            ])->values()->all(),
            'decision' => $case->status->isDecided() && $decision !== null ? [
                'outcome' => $case->status->value,
                'reason' => $decision['reason'] ?? null,
                // The refund is the rider's; the captain's decision says what was decided, not whose money moved.
                'refund_minor' => $viewer === 'driver' ? null : ($decision['refund_minor'] ?? 0),
                'refund_applied' => $viewer === 'driver' ? null : (bool) ($decision['refund_applied'] ?? false),
                'decided_at' => $case->decided_at?->toIso8601String(),
            ] : null,
            'can_message' => ! $admin && (! $case->status->isDecided()),
            'can_appeal' => ! $admin && $this->canAppeal($case),
            'appealed_at' => $case->appealed_at?->toIso8601String(),
            'timeline' => $case->events->whereIn('type', ['opened', 'in_review', 'needs_info', 'decided', 'appealed'])->map(fn (RideCaseEvent $e) => [
                'type' => $e->type, 'at' => $e->created_at?->toIso8601String(),
            ])->values()->all(),
            'created_at' => $case->created_at?->toIso8601String(),
        ];

        if ($admin) {
            $reporter = $case->reporter();
            $payload += [
                'id' => $case->id,
                'ride_id' => $case->ride_id,
                'priority' => $case->priority,
                'reporter' => ['type' => $case->reporter_type, 'id' => $case->reporter_id, 'name' => $reporter?->name, 'phone' => $reporter?->phone],
                'assigned_to' => $case->assignedTo?->name,
                'decision_full' => $decision,
                'decided_by' => $case->decidedBy?->name,
                // AI-12: DORR AI's reading (a suggestion; the category, priority and decision stay the team's).
                'ai_triage' => $case->ai_triage,
                'appeal_note' => $case->appeal_note,
                'events' => $case->events->map(fn (RideCaseEvent $e) => ['type' => $e->type, 'actor_type' => $e->actor_type, 'actor_id' => $e->actor_id, 'payload' => $e->payload, 'at' => $e->created_at?->toIso8601String()])->values()->all(),
            ];
        }

        return $payload;
    }

    public function message(RideCase $case, ?Model $author, string $body, bool $internal = false): RideCaseMessage
    {
        $admin = $author instanceof Admin || $author === null;
        if (! $admin && $case->status->isDecided()) {
            throw new RidesException('case_closed');
        }

        return DB::transaction(function () use ($case, $author, $body, $internal, $admin) {
            $message = RideCaseMessage::query()->create([
                'case_id' => $case->id,
                'author_type' => $admin ? 'admin' : self::partyType($author),
                'author_id' => $author?->getKey(),
                'internal' => $admin && $internal,
                'body' => trim($body),
                'created_at' => now(),
            ]);
            if (! $admin && $case->status === CaseStatus::NeedsInfo) {
                $case->forceFill(['status' => CaseStatus::InReview])->save();
                $this->log($case, 'in_review', $author, ['after' => 'info_given']);
            }
            if ($admin && ! $internal && ($reporter = $case->reporter()) !== null) {
                $this->center->send($reporter, 'ride.case_message', 'ride_case_message_title', 'ride_case_message_body', ['number' => $case->number], ['type' => 'ride_case', 'case_uuid' => $case->uuid]);
            }

            return $message;
        });
    }

    public function attach(RideCase $case, ?Model $uploader, UploadedFile $file): RideCaseFile
    {
        $admin = $uploader instanceof Admin || $uploader === null;
        if (! $admin && $case->status->isDecided()) {
            throw new RidesException('case_closed');
        }
        if ($case->files()->count() >= (int) config('rides.cases.max_files', 6)) {
            throw new RidesException('case_too_many_files', 422, ['max' => (int) config('rides.cases.max_files', 6)]);
        }

        return RideCaseFile::query()->create([
            'case_id' => $case->id,
            'uploader_type' => $admin ? 'admin' : self::partyType($uploader),
            'uploader_id' => $uploader?->getKey(),
            'path' => $file->store('ride-cases/'.$case->uuid, 'public'),
            'name' => Str::limit($file->getClientOriginalName(), 150, ''),
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'created_at' => now(),
        ]);
    }

    /** The team takes a case, or asks the person for more (statuses only; the ride and the money are untouched). */
    public function setStatus(RideCase $case, Admin $admin, CaseStatus $status): RideCase
    {
        if ($status->isDecided() || $status === CaseStatus::Submitted) {
            throw new RidesException('case_status_invalid');
        }
        if ($case->status->isDecided()) {
            throw new RidesException('case_already_decided', 409);
        }
        $case->forceFill(['status' => $status, 'assigned_admin_id' => $case->assigned_admin_id ?? $admin->id])->save();
        $this->log($case, $status->value, $admin);
        if ($status === CaseStatus::NeedsInfo && ($reporter = $case->reporter()) !== null) {
            $this->center->send($reporter, 'ride.case_message', 'ride_case_info_title', 'ride_case_info_body', ['number' => $case->number], ['type' => 'ride_case', 'case_uuid' => $case->uuid]);
        }

        return $case;
    }

    /**
     * The decision: an outcome, the reason the person is shown, and — only when the admin asks for it —
     * a refund to the rider's wallet as in-app credit.
     *
     * @param  array{outcome: string, reason: string, refund_minor?: int|null, apply_refund?: bool}  $in
     */
    public function decide(RideCase $case, Admin $admin, array $in): RideCase
    {
        $outcome = CaseStatus::tryFrom((string) $in['outcome']);
        if ($outcome === null || ! $outcome->isDecided()) {
            throw new RidesException('case_status_invalid');
        }
        $previous = (array) ($case->decision ?? []);
        // Decided once; an appeal sends it back to review, and only then can it be decided again.
        if ($case->status->isDecided()) {
            throw new RidesException('case_already_decided', 409);
        }

        $refund = max(0, (int) ($in['refund_minor'] ?? 0));
        $apply = $refund > 0 && ! empty($in['apply_refund']);
        if ($refund > 0) {
            if ($case->reporter_type !== 'user' || $case->ride === null) {
                throw new RidesException('case_refund_not_possible');
            }
            if ($refund > (int) ($case->disputed_minor ?? $case->ride->final_total_minor ?? 0)) {
                throw new RidesException('case_refund_too_large');
            }
            if (($previous['refund_applied'] ?? false) && $apply) {
                throw new RidesException('case_refund_already_applied');
            }
        }

        return DB::transaction(function () use ($case, $admin, $in, $outcome, $refund, $apply, $previous) {
            $tx = null;
            if ($apply) {
                $tx = $this->refund($case, $admin, $refund);
            }
            $case->forceFill([
                'status' => $outcome,
                'decision' => [
                    'reason' => trim((string) $in['reason']),
                    'refund_minor' => $refund,
                    'refund_applied' => $apply || (bool) ($previous['refund_applied'] ?? false),
                    'refund_tx' => $tx?->uuid ?? ($previous['refund_tx'] ?? null),
                ],
                'decided_by_admin_id' => $admin->id,
                'decided_at' => now(),
                'assigned_admin_id' => $case->assigned_admin_id ?? $admin->id,
            ])->save();
            $this->log($case, 'decided', $admin, ['outcome' => $outcome->value, 'refund_minor' => $refund, 'refund_applied' => $apply, 'after_appeal' => $case->appealed_at !== null]);
            if (($reporter = $case->reporter()) !== null) {
                $this->center->send($reporter, 'ride.case_decided', 'ride_case_decided_title', 'ride_case_decided_body', ['number' => $case->number, 'outcome' => __('rides.cases.status.'.$outcome->value)], ['type' => 'ride_case', 'case_uuid' => $case->uuid]);
            }

            return $case;
        });
    }

    public function canAppeal(RideCase $case): bool
    {
        if (! $case->status->isDecided() || $case->appealed_at !== null || $case->country_id === null) {
            return false;
        }
        $policy = $this->policies->for((int) $case->country_id);
        if (! $policy->bool('cases.appeal_allowed')) {
            return false;
        }
        $window = $policy->int('cases.appeal_window_h');

        return $window === 0 || ($case->decided_at !== null && $case->decided_at->gte(now()->subHours($window)));
    }

    /** One appeal, only where the country allows it (R-140, D-237): the case goes back to review. */
    public function appeal(RideCase $case, Model $party, string $note): RideCase
    {
        if (! $case->isOwnedBy($party)) {
            throw new RidesException('case_not_found', 404);
        }
        if (! $this->canAppeal($case)) {
            throw new RidesException('case_cannot_appeal');
        }
        $case->forceFill(['status' => CaseStatus::InReview, 'appealed_at' => now(), 'appeal_note' => trim($note)])->save();
        $this->log($case, 'appealed', $party);

        return $case;
    }

    private function available(Model $party): bool
    {
        return $party->country_id !== null && $this->features->allows((int) $party->country_id, null, 'cases');
    }

    private function disputedAmount(Ride $ride, string $reason): ?int
    {
        return match ($reason) {
            'waiting_fee' => (int) RidePriceLine::query()->where('ride_id', $ride->id)->where('phase', 'final')->where('code', 'waiting')->sum('amount_minor'),
            'cancel_fee' => (int) RideAdjustment::query()->where('ride_id', $ride->id)->where('type', 'cancel_fee')->sum('amount_minor'),
            'no_show_fee' => (int) RideAdjustment::query()->where('ride_id', $ride->id)->where('type', 'no_show_fee')->sum('amount_minor'),
            'fare' => $ride->final_total_minor === null ? null : (int) $ride->final_total_minor,
            default => null,
        };
    }

    /** The refund as in-app (spend-only) credit, paid by DORR, with its ledger entry. */
    private function refund(RideCase $case, Admin $admin, int $amountMinor): WalletTransaction
    {
        $ride = $case->ride;
        $rider = User::query()->findOrFail($case->reporter_id);
        $country = Country::query()->findOrFail($ride->country_id);
        $wallet = $this->wallets->firstOrCreateWallet($rider, $country);
        $notes = ['key' => 'wallet.notes.ride_case_refund', 'variables' => ['number' => $ride->number]];
        $tx = $this->wallets->credit($wallet, $amountMinor, WalletBucket::SpendOnly, WalletTransactionType::Refund, [
            'reference_type' => 'ride',
            'reference_id' => $ride->id,
            'notes' => $notes,
            'idempotency_key' => "ride-case:{$case->uuid}:refund",
            'created_by_type' => 'admin',
            'created_by_id' => $admin->id,
        ]);
        $this->ledger->record('manual_adjustment_expense', FinancialEntryType::Expense, $amountMinor, Currency::query()->findOrFail($ride->currency_id), $country, $ride, $notes, $tx, null, ['type' => 'admin', 'id' => $admin->id]);

        return $tx;
    }

    /** @return array{code: string, symbol: string, decimal_places: int}|null */
    private function currency(?int $id): ?array
    {
        $currency = $id === null ? null : Currency::query()->find($id);

        return $currency === null ? null : ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimal_places' => (int) ($currency->decimal_places ?? 2)];
    }

    private function number(): string
    {
        do {
            $number = 'C'.random_int(1000000, 9999999);
        } while (RideCase::query()->where('number', $number)->exists());

        return $number;
    }

    /** @param  array<string, mixed>  $payload */
    private function log(RideCase $case, string $type, ?Model $actor, array $payload = []): void
    {
        RideCaseEvent::query()->create([
            'case_id' => $case->id,
            'type' => $type,
            'actor_type' => $actor === null ? 'system' : ($actor instanceof Admin ? 'admin' : self::partyType($actor)),
            'actor_id' => $actor?->getKey(),
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }
}
