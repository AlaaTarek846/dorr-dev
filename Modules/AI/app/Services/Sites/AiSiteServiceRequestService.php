<?php

namespace Modules\AI\Services\Sites;

use App\Models\Country;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Exceptions\AiSubscriptionException;
use Modules\AI\Models\AiSiteRequestInstallment as Installment;
use Modules\AI\Models\AiSiteRequestMessage;
use Modules\AI\Models\AiSiteRequestMilestone as Milestone;
use Modules\AI\Models\AiSiteRequestStatus as Status;
use Modules\AI\Models\AiSiteServiceRequest;
use Modules\AI\Models\AiSiteServiceSetting;
use Modules\AI\Services\AiSubscriptionBillingService;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\InsufficientBalanceException;

/**
 * The "build it with a person" workflow: brief -> quote (price, duration, deliverables, instalments) -> accept and pay
 * the first instalment -> countdown and deliveries -> remaining instalments -> handover. The customer side and the
 * admin side live together so the state rules are written once.
 */
class AiSiteServiceRequestService
{
    public function __construct(
        protected AiSubscriptionBillingService $billing,
        protected NotificationCenter $center,
    ) {}

    // ------------------------------------------------------------------ settings

    public function enabled(): bool
    {
        return config('ai.sites.enabled', true) && AiSiteServiceSetting::current()->is_enabled;
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new AiSiteException('human_disabled', 403);
        }
    }

    // ------------------------------------------------------------------ customer

    /**
     * @param  array<string, mixed>  $brief
     * @param  list<UploadedFile>  $files
     */
    public function create(Authenticatable $owner, array $brief, array $files = []): AiSiteServiceRequest
    {
        $this->assertEnabled();

        $country = currentCountry();
        $currency = null;

        if ($country !== null) {
            try {
                $currency = $this->billing->walletFor($owner, $country)->currency?->code;
            } catch (\Throwable) {
                $currency = null;
            }
        }

        return DB::transaction(function () use ($owner, $brief, $files, $country, $currency) {
            $request = AiSiteServiceRequest::query()->create([
                'reference' => 'WS-'.strtoupper(Str::random(7)),
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getAuthIdentifier(),
                'title' => Str::limit((string) $brief['business_name'], 150, ''),
                'site_type' => $brief['site_type'] ?? null,
                'status' => Status::SUBMITTED,
                'brief' => $brief,
                'country_id' => $country?->id,
                'currency' => $currency,
            ]);

            $stored = [];

            foreach (array_slice($files, 0, 6) as $file) {
                $name = Str::random(12).'.'.($file->getClientOriginalExtension() ?: 'bin');
                $path = $file->storeAs('ai-site-requests/'.$request->id, $name, 'local');
                $stored[] = ['name' => Str::limit($file->getClientOriginalName(), 120, ''), 'path' => $path, 'size' => $file->getSize()];
            }

            $request->forceFill(['files' => $stored])->save();

            $this->system($request, 'request_received');
            $this->notify($request, 'received');
            $this->notifyAdmins($request, 'new');

            return $request;
        });
    }

    public function cancel(AiSiteServiceRequest $request): AiSiteServiceRequest
    {
        if (! in_array($request->status, [Status::SUBMITTED, Status::UNDER_REVIEW, Status::QUOTE_SENT, Status::QUOTE_DECLINED], true)) {
            throw new AiSiteException('request_invalid_state', 409);
        }

        return $this->moveTo($request, Status::CANCELLED, notify: false);
    }

    public function decline(AiSiteServiceRequest $request, ?string $reason): AiSiteServiceRequest
    {
        if ($request->status !== Status::QUOTE_SENT) {
            throw new AiSiteException('request_invalid_state', 409);
        }

        $request->forceFill(['decline_reason' => $reason])->save();

        $this->notifyAdmins($request, 'declined', ['text' => (string) $reason]);

        return $this->moveTo($request, Status::QUOTE_DECLINED, $reason, notify: false);
    }

    /** Accepts the quote: takes every "on accept" instalment from the wallet at once and starts the countdown. */
    public function accept(AiSiteServiceRequest $request, Authenticatable $owner): AiSiteServiceRequest
    {
        if ($request->status !== Status::QUOTE_SENT) {
            throw new AiSiteException('request_invalid_state', 409);
        }

        if ($request->quote_valid_until !== null && $request->quote_valid_until->isPast()) {
            throw new AiSiteException('quote_expired', 422);
        }

        return DB::transaction(function () use ($request, $owner) {
            $request = AiSiteServiceRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status !== Status::QUOTE_SENT) {
                throw new AiSiteException('request_invalid_state', 409);
            }

            $first = $request->installments()->where('trigger', Installment::ON_ACCEPT)->where('status', '!=', Installment::PAID)->get();
            $operation = $first->isEmpty() ? null : $this->charge($request, $owner, (float) $first->sum('amount'));

            foreach ($first as $installment) {
                $installment->forceFill(['status' => Installment::PAID, 'paid_at' => now(), 'wallet_operation_id' => $operation])->save();
            }

            $now = now();
            $request->forceFill([
                'accepted_at' => $now,
                'started_at' => $now,
                'due_at' => $now->copy()->addDays((int) $request->quote_duration_days),
            ])->save();

            foreach ($request->milestones as $milestone) {
                $milestone->forceFill(['due_at' => $now->copy()->addDays($milestone->due_days)])->save();
            }

            $this->system($request, 'quote_accepted');
            $this->notifyAdmins($request, 'accepted', ['amount' => $first->sum('amount').' '.$request->currency]);

            return $this->moveTo($request, Status::IN_PROGRESS, notify: false);
        });
    }

    /** Pays one instalment the admin has released. The last payment hands the finished site over. */
    public function pay(AiSiteServiceRequest $request, int $installmentId, Authenticatable $owner): AiSiteServiceRequest
    {
        return DB::transaction(function () use ($request, $installmentId, $owner) {
            $request = AiSiteServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
            $installment = $request->installments()->whereKey($installmentId)->lockForUpdate()->first();

            if ($installment === null) {
                throw new AiSiteException('installment_not_found', 404);
            }

            if ($installment->status !== Installment::DUE || $request->isFinal()) {
                throw new AiSiteException('installment_not_due', 422);
            }

            $operation = $this->charge($request, $owner, (float) $installment->amount);
            $installment->forceFill(['status' => Installment::PAID, 'paid_at' => now(), 'wallet_operation_id' => $operation])->save();

            $this->system($request, 'installment_paid', ['title' => $installment->title]);
            $this->notifyAdmins($request, 'paid', ['item' => $installment->title, 'amount' => $installment->amount.' '.$request->currency]);
            $this->settleIfHandedOver($request->refresh());

            return $request->refresh();
        });
    }

    public function customerMessage(AiSiteServiceRequest $request, string $body): AiSiteRequestMessage
    {
        if ($request->isFinal() && $request->status !== Status::COMPLETED) {
            throw new AiSiteException('request_invalid_state', 409);
        }

        $message = $request->messages()->create(['sender' => AiSiteRequestMessage::FROM_CUSTOMER, 'sender_id' => $request->owner_id, 'body' => $body]);
        $this->notifyAdmins($request, 'message', ['text' => Str::limit($body, 90)]);

        return $message;
    }

    public function markMessagesRead(AiSiteServiceRequest $request): void
    {
        $request->messages()->whereIn('sender', [AiSiteRequestMessage::FROM_ADMIN, AiSiteRequestMessage::FROM_SYSTEM])->whereNull('read_at')->update(['read_at' => now()]);
    }

    // ------------------------------------------------------------------ admin

    /**
     * Sends (or re-sends) the quote. Instalments must add up to the price; the first one is taken on acceptance.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendQuote(AiSiteServiceRequest $request, array $data): AiSiteServiceRequest
    {
        if (! in_array($request->status, [Status::SUBMITTED, Status::UNDER_REVIEW, Status::QUOTE_SENT, Status::QUOTE_DECLINED], true)) {
            throw new AiSiteException('request_invalid_state', 409);
        }

        $amount = round((float) $data['amount'], 2);
        $installments = collect($data['installments']);

        if (abs($installments->sum(fn ($i) => (float) $i['amount']) - $amount) > 0.009) {
            throw new AiSiteException('quote_invalid_total', 422);
        }

        if (! $installments->contains(fn ($i) => $i['trigger'] === Installment::ON_ACCEPT)) {
            throw new AiSiteException('quote_needs_first_payment', 422);
        }

        $milestones = collect($data['milestones'] ?? []);

        return DB::transaction(function () use ($request, $data, $amount, $installments, $milestones) {
            $request->installments()->delete();
            $request->milestones()->delete();

            $created = [];

            foreach ($milestones->values() as $i => $m) {
                $created[$i] = $request->milestones()->create([
                    'title' => $m['title'], 'description' => $m['description'] ?? null, 'due_days' => (int) $m['due_days'], 'sort_order' => $i,
                ]);
            }

            foreach ($installments->values() as $i => $p) {
                $milestone = $p['trigger'] === Installment::ON_MILESTONE ? ($created[(int) ($p['milestone_index'] ?? -1)] ?? null) : null;

                if ($p['trigger'] === Installment::ON_MILESTONE && $milestone === null) {
                    throw new AiSiteException('quote_invalid_milestone', 422);
                }

                $request->installments()->create([
                    'title' => $p['title'], 'amount' => $p['amount'], 'trigger' => $p['trigger'], 'milestone_id' => $milestone?->id, 'sort_order' => $i,
                ]);
            }

            $validDays = (int) ($data['valid_days'] ?? AiSiteServiceSetting::current()->quote_valid_days);

            $request->forceFill([
                'quote_amount' => $amount,
                'quote_duration_days' => (int) $data['duration_days'],
                'quote_notes' => $data['notes'] ?? null,
                'quote_sent_at' => now(),
                'quote_valid_until' => now()->addDays(max(1, $validDays)),
                'decline_reason' => null,
                'currency' => $request->currency ?? ($data['currency'] ?? null),
            ])->save();

            return $this->moveTo($request, Status::QUOTE_SENT, notifyKey: 'quote_sent');
        });
    }

    /** Free status change from the dashboard; the workflow side effects hang off specific codes. */
    public function changeStatus(AiSiteServiceRequest $request, string $code, ?string $note = null): AiSiteServiceRequest
    {
        Status::ensureDefaults();
        $status = Status::query()->where('code', $code)->where('is_active', true)->first();

        if ($status === null) {
            throw new AiSiteException('status_unknown', 422);
        }

        if ($request->isFinal() && $request->status !== $code) {
            throw new AiSiteException('request_invalid_state', 409);
        }

        if (in_array($code, [Status::IN_PROGRESS, Status::DELIVERED, Status::COMPLETED], true) && $request->accepted_at === null) {
            throw new AiSiteException('request_not_accepted', 422);
        }

        if ($code === Status::COMPLETED && ! $request->allPaid()) {
            throw new AiSiteException('unpaid_installments', 422);
        }

        return DB::transaction(function () use ($request, $code, $note) {
            if ($code === Status::DELIVERED) {
                $request->forceFill(['delivered_at' => now()])->save();
                $this->release($request, fn ($i) => $i->trigger === Installment::ON_DELIVERY);
            }

            if ($code === Status::COMPLETED) {
                $request->forceFill(['completed_at' => now()])->save();
            }

            return $this->moveTo($request, $code, $note);
        });
    }

    public function setMilestoneStatus(AiSiteServiceRequest $request, Milestone $milestone, string $status): AiSiteServiceRequest
    {
        if ($request->accepted_at === null || $request->isFinal()) {
            throw new AiSiteException('request_invalid_state', 409);
        }

        return DB::transaction(function () use ($request, $milestone, $status) {
            $milestone->forceFill([
                'status' => $status,
                'delivered_at' => $status === Milestone::DELIVERED ? now() : null,
            ])->save();

            if ($status === Milestone::DELIVERED) {
                $this->system($request, 'milestone_delivered', ['title' => $milestone->title]);
                $this->release($request, fn ($i) => $i->milestone_id === $milestone->id);
                $this->notify($request, 'milestone', ['milestone' => $milestone->title]);
            }

            return $request->refresh();
        });
    }

    /** Releases an instalment by hand (e.g. a payment due on a date rather than a deliverable). */
    public function releaseInstallment(AiSiteServiceRequest $request, int $installmentId): AiSiteServiceRequest
    {
        $installment = $request->installments()->whereKey($installmentId)->first();

        if ($installment === null || $installment->status !== Installment::PENDING || $request->accepted_at === null) {
            throw new AiSiteException('installment_not_found', 404);
        }

        $this->release($request, fn ($i) => $i->id === $installment->id);

        return $request->refresh();
    }

    public function extend(AiSiteServiceRequest $request, \DateTimeInterface $dueAt, ?string $reason): AiSiteServiceRequest
    {
        if ($request->accepted_at === null || $request->isFinal()) {
            throw new AiSiteException('request_invalid_state', 409);
        }

        $request->forceFill(['due_at' => $dueAt])->save();
        $this->system($request, 'deadline_changed', ['date' => $request->due_at->format('Y-m-d')]);
        $this->notify($request, 'deadline', ['date' => $request->due_at->format('Y-m-d')]);

        return $request->refresh();
    }

    public function adminMessage(AiSiteServiceRequest $request, ?int $adminId, string $body): AiSiteRequestMessage
    {
        $message = $request->messages()->create(['sender' => AiSiteRequestMessage::FROM_ADMIN, 'sender_id' => $adminId, 'body' => $body]);
        $this->notify($request, 'message', ['text' => Str::limit($body, 90)]);

        return $message;
    }

    /** @param  array<string, mixed>  $data */
    public function updateInternal(AiSiteServiceRequest $request, array $data): AiSiteServiceRequest
    {
        $request->fill(array_intersect_key($data, array_flip(['admin_notes', 'delivery_url', 'delivery_notes'])))->save();

        return $request->refresh();
    }

    // ------------------------------------------------------------------ plumbing

    /** @param  callable(Installment): bool  $match */
    protected function release(AiSiteServiceRequest $request, callable $match): void
    {
        $released = $request->installments()->where('status', Installment::PENDING)->get()->filter($match);

        foreach ($released as $installment) {
            $installment->forceFill(['status' => Installment::DUE, 'due_at' => now()])->save();
            $this->system($request, 'installment_due', ['title' => $installment->title, 'amount' => $installment->amount.' '.$request->currency]);
            $this->notify($request, 'installment', ['item' => $installment->title, 'amount' => $installment->amount.' '.$request->currency]);
        }
    }

    /** Once delivered and fully paid the request is complete and the customer gets the handover. */
    protected function settleIfHandedOver(AiSiteServiceRequest $request): void
    {
        if ($request->status === Status::DELIVERED && $request->allPaid()) {
            $request->forceFill(['completed_at' => now()])->save();
            $this->moveTo($request, Status::COMPLETED, notifyKey: 'completed');
        }
    }

    protected function moveTo(AiSiteServiceRequest $request, string $code, ?string $note = null, bool $notify = true, string $notifyKey = 'status'): AiSiteServiceRequest
    {
        $request->forceFill(['status' => $code])->save();

        $label = (Status::map()[$code] ?? null)?->label() ?? $code;
        $this->system($request, 'status_changed', ['status' => $label, 'note' => $note ? ' - '.$note : '']);

        if ($notify) {
            $this->notify($request, $notifyKey, ['status' => $label]);
        }

        return $request->refresh();
    }

    /** @param  array<string, scalar|null>  $vars */
    protected function system(AiSiteServiceRequest $request, string $key, array $vars = []): void
    {
        $request->messages()->create([
            'sender' => AiSiteRequestMessage::FROM_SYSTEM,
            'body' => __('ai.site_req_sys_'.$key, $vars + ['status' => '', 'note' => '', 'title' => '', 'amount' => '', 'date' => '']),
        ]);
    }

    /** @param  array<string, scalar|null>  $vars */
    protected function notify(AiSiteServiceRequest $request, string $key, array $vars = []): void
    {
        $owner = $request->owner;

        if ($owner === null) {
            return;
        }

        $this->center->send(
            $owner,
            'ai_site_request.'.$key,
            'ai_site_request_'.$key.'_title',
            'ai_site_request_'.$key.'_body',
            $vars + ['reference' => $request->reference, 'title' => $request->title, 'status' => '', 'amount' => '', 'date' => '', 'text' => '', 'milestone' => '', 'item' => ''],
            ['type' => 'ai_site_request', 'request_id' => $request->id],
        );
    }

    /** Tells the admins who can handle requests (super-admins always) - in-app list and live toast, no phone push. */
    protected function notifyAdmins(AiSiteServiceRequest $request, string $key, array $vars = []): void
    {
        $admins = $this->center->adminsWith('ai-site-requests.view');

        if ($admins->isEmpty()) {
            return;
        }

        $owner = $request->owner;

        $this->center->send(
            $admins,
            'ai_site_request.admin_'.$key,
            'ai_site_request_admin_'.$key.'_title',
            'ai_site_request_admin_'.$key.'_body',
            $vars + ['reference' => $request->reference, 'title' => $request->title, 'name' => (string) ($owner?->name ?? '#'.$request->owner_id), 'amount' => '', 'text' => '', 'item' => ''],
            ['type' => 'ai_site_request_admin', 'request_id' => $request->id],
            push: false,
        );
    }

    protected function charge(AiSiteServiceRequest $request, Authenticatable $owner, float $amount): string
    {
        $country = $request->country_id ? Country::query()->find($request->country_id) : currentCountry();

        try {
            $wallet = $this->billing->walletFor($owner, $country);
            $this->billing->assertCurrencyMatches($wallet, (string) ($request->currency ?? $wallet->currency?->code));
            $result = $this->billing->charge($wallet, $amount, WalletTransactionType::ServicePayment, [
                'reference_type' => 'ai_site_service_request',
                'reference_id' => $request->id,
            ]);
        } catch (InsufficientBalanceException) {
            throw new AiSiteException('insufficient_balance', 402);
        } catch (AiSubscriptionException $e) {
            throw new AiSiteException($e->getMessage() === 'subscription_currency_mismatch' ? 'currency_mismatch' : 'country_not_resolved', 422);
        }

        return (string) ($result['operation_id'] ?? '');
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<string, mixed> */
    public function present(AiSiteServiceRequest $request, bool $detailed = true, bool $forAdmin = false): array
    {
        $request->loadMissing(['milestones', 'installments']);
        $statuses = Status::map();
        $status = $statuses[$request->status] ?? null;
        $installments = $request->installments;
        $total = (float) $installments->sum('amount');
        $paid = (float) $installments->where('status', Installment::PAID)->sum('amount');
        $handover = $request->status === Status::COMPLETED || ($request->allPaid() && $request->status === Status::DELIVERED);

        $data = [
            'id' => $request->id,
            'reference' => $request->reference,
            'title' => $request->title,
            'site_type' => $request->site_type,
            'status' => [
                'code' => $request->status,
                'name' => $status?->label() ?? $request->status,
                'color' => $status?->color ?? 'secondary',
                'final' => $request->isFinal(),
            ],
            'currency' => $request->currency,
            'quote' => $request->quote_amount === null ? null : [
                'amount' => $request->quote_amount,
                'duration_days' => $request->quote_duration_days,
                'valid_until' => $request->quote_valid_until?->toIso8601String(),
                'expired' => $request->status === Status::QUOTE_SENT && $request->quote_valid_until?->isPast() === true,
                'notes' => $request->quote_notes,
                'sent_at' => $request->quote_sent_at?->toIso8601String(),
                'can_accept' => $request->status === Status::QUOTE_SENT && $request->quote_valid_until?->isFuture() === true,
            ],
            'timeline' => [
                'accepted_at' => $request->accepted_at?->toIso8601String(),
                'started_at' => $request->started_at?->toIso8601String(),
                'due_at' => $request->due_at?->toIso8601String(),
                'remaining_seconds' => $request->due_at && ! $request->isFinal() && $request->status !== Status::DELIVERED ? max(0, now()->diffInSeconds($request->due_at, false)) : null,
                'overdue' => $request->due_at && ! $request->isFinal() && $request->status !== Status::DELIVERED && $request->due_at->isPast(),
                'delivered_at' => $request->delivered_at?->toIso8601String(),
                'completed_at' => $request->completed_at?->toIso8601String(),
            ],
            'payments' => ['total' => $total, 'paid' => $paid, 'remaining' => max(0, $total - $paid)],
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];

        $data['unread'] = $forAdmin
            ? $request->messages()->where('sender', AiSiteRequestMessage::FROM_CUSTOMER)->whereNull('read_at')->count()
            : $request->messages()->whereIn('sender', [AiSiteRequestMessage::FROM_ADMIN, AiSiteRequestMessage::FROM_SYSTEM])->whereNull('read_at')->count();

        if (! $detailed) {
            return $data;
        }

        return $data + [
            'brief' => $request->brief,
            'files' => collect($request->files ?? [])->map(fn ($f, $i) => ['index' => $i, 'name' => $f['name'] ?? 'file', 'size' => $f['size'] ?? null])->values()->all(),
            'decline_reason' => $request->decline_reason,
            'milestones' => $request->milestones->map(fn (Milestone $m) => [
                'id' => $m->id, 'title' => $m->title, 'description' => $m->description, 'due_days' => $m->due_days,
                'due_at' => $m->due_at?->toIso8601String(), 'status' => $m->status, 'delivered_at' => $m->delivered_at?->toIso8601String(),
            ])->values()->all(),
            'installments' => $installments->map(fn (Installment $i) => [
                'id' => $i->id, 'title' => $i->title, 'amount' => $i->amount, 'trigger' => $i->trigger, 'milestone_id' => $i->milestone_id,
                'status' => $i->status, 'due_at' => $i->due_at?->toIso8601String(), 'paid_at' => $i->paid_at?->toIso8601String(),
                'can_pay' => $i->status === Installment::DUE && ! $request->isFinal(),
            ])->values()->all(),
            'delivery' => $handover && $request->allPaid() ? ['url' => $request->delivery_url, 'notes' => $request->delivery_notes] : null,
            'admin' => $forAdmin ? [
                'notes' => $request->admin_notes,
                'delivery_url' => $request->delivery_url,
                'delivery_notes' => $request->delivery_notes,
                'owner' => ['type' => $request->owner_type, 'id' => $request->owner_id, 'name' => $request->owner?->name ?? $request->owner?->full_name ?? null],
            ] : null,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function presentMessages(AiSiteServiceRequest $request): Collection
    {
        return $request->messages->map(fn (AiSiteRequestMessage $m) => [
            'id' => $m->id, 'sender' => $m->sender, 'body' => $m->body, 'read' => $m->read_at !== null, 'created_at' => $m->created_at?->toIso8601String(),
        ]);
    }

    public function filePath(AiSiteServiceRequest $request, int $index): ?string
    {
        $path = ($request->files[$index]['path'] ?? null);

        return $path !== null && Storage::disk('local')->exists($path) ? Storage::disk('local')->path($path) : null;
    }
}
