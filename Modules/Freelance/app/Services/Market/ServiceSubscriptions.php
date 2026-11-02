<?php

namespace Modules\Freelance\Services\Market;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceServiceOrder as Order;
use Modules\Freelance\Models\FreelanceServiceSubscription as Sub;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Moderation\Sanctions;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Projects\Agreements;
use Modules\Freelance\Services\Projects\MilestonePayments;
use Modules\User\Models\User;

/**
 * Monthly service subscriptions (FL-L05). A service sold by the month is ordered like any other; that order is the first month of a
 * subscription which keeps the level, the extras and the price as they were. Once the freelancer accepts the first month it is active.
 *
 * **The client confirms every month.** From `renew_window_days` before the month ends until `grace_days` after, the client confirms the
 * next one: it is made at the kept terms, starts at once (the freelancer agreed to the subscription) and its money is held from the wallet
 * in the same step — not enough money changes nothing and says what is missing. Not confirmed in time, the subscription ends.
 *
 * **Ending takes notice.** Either side may ask to end it. Asked at least `cancel_notice_days` before the month ends, it ends with that
 * month; asked later, it ends with the next one (which the client still confirms). The one who asked may take it back until then.
 *
 * An active subscription is one running project for the freelancer's capacity (FL-L10) for as long as it lasts, between months too.
 */
class ServiceSubscriptions
{
    public function __construct(private readonly FreelanceNotifier $notifier) {}

    public static function periodDays(): int
    {
        return max(1, (int) config('freelance.subscriptions.period_days', 30));
    }

    public static function renewWindowDays(): int
    {
        return max(1, (int) config('freelance.subscriptions.renew_window_days', 3));
    }

    public static function graceDays(): int
    {
        return max(0, (int) config('freelance.subscriptions.grace_days', 2));
    }

    public static function noticeDays(): int
    {
        return max(0, (int) config('freelance.subscriptions.cancel_notice_days', 7));
    }

    public function activeCount(int $freelancerId): int
    {
        return Sub::query()->where('freelancer_id', $freelancerId)->where('status', Sub::ACTIVE)->count();
    }

    /** The order's agreement moved: the first month accepted starts the subscription, the first month never started ends it. */
    public function agreementChanged(Agreement $a): void
    {
        $order = Order::query()->where('project_id', $a->project_id)->whereNotNull('subscription_id')->first();
        if ($order === null || (int) $order->cycle !== 1) {
            return;
        }
        $sub = Sub::query()->whereKey($order->subscription_id)->lockForUpdate()->first();
        if ($sub === null || $sub->status !== Sub::PENDING) {
            return;
        }
        if ($a->status === Agreement::ACTIVE) {
            $start = $a->activated_at ?? now();
            $sub->fill(['status' => Sub::ACTIVE, 'started_at' => $start, 'period_ends_at' => $start->copy()->addDays(self::periodDays())])->save();
        } elseif (in_array($a->status, [Agreement::DECLINED, Agreement::EXPIRED, Agreement::CANCELLED], true)) {
            $sub->fill(['status' => Sub::ENDED, 'ended_at' => now(), 'end_reason' => 'first_'.$a->status])->save();
        }
    }

    /** The client confirms the next month: made at the kept terms, started, and its money held — all or nothing. */
    public function renew(Sub $sub, User $client): Sub
    {
        [$s, $order] = DB::transaction(function () use ($sub, $client) {
            /** @var Sub $s */
            $s = Sub::query()->whereKey($sub->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $s->client_id === (int) $client->id, 404);
            if (! $this->renewOpen($s)) {
                throw new FreelanceException('subscription_renew_closed', 409);
            }
            $service = $s->service()->with(['freelancer', 'country.currency'])->firstOrFail();
            $f = $service->freelancer;
            if ($f === null || $f->trashed() || $f->status !== FreelancerStatus::Verified || app(Sanctions::class)->restrictions($f)['work'] !== null) {
                throw new FreelanceException('service_unavailable', 409);
            }

            $bought = (array) $s->snapshot + ['level' => $s->level, 'price_minor' => $s->price_minor, 'delivery_days' => $s->delivery_days, 'revisions' => $s->revisions];
            $order = app(ServiceOrders::class)->book($service, $client, $bought, $s->note, null, now()->addDay());
            $order->forceFill(['subscription_id' => $s->id, 'cycle' => $s->cycle + 1])->save();

            /** @var Agreement $agreement */
            $agreement = Agreement::query()->where('project_id', $order->project_id)->with(['project', 'milestones'])->firstOrFail();
            $part = $agreement->milestones->sortBy('id')->first();
            // The month's money must be there before anything starts.
            $payments = app(MilestonePayments::class);
            $needed = (int) $agreement->milestones->sum(fn ($m) => $m->amount_minor + $m->client_fee_minor);
            $available = $payments->availableFor($client, (int) $service->country_id);
            if ($available < $needed) {
                $money = fn (int $v) => MilestonePayments::money($v, (string) $s->currency_code);
                throw new FreelanceException('wallet_insufficient', 422, ['needed' => $money($needed), 'available' => $money($available)],
                    ['needed_minor' => $needed, 'available_minor' => $available, 'shortfall_minor' => $needed - $available, 'currency' => $s->currency_code]);
            }
            app(Agreements::class)->approve($agreement, $f);
            $payments->fund($part, $client);

            $s->fill(['cycle' => $s->cycle + 1, 'period_ends_at' => $s->period_ends_at->copy()->addDays(self::periodDays()), 'renew_reminded_at' => null])->save();

            return [$s, $order];
        });

        $this->notifier->notify($s->freelancer, 'retainer.renewed', ['service' => (string) ($s->snapshot['title'] ?? ''), 'name' => (string) $client->name, 'cycle' => (string) $s->cycle],
            ['subscription' => $s->uuid, 'order' => $order->uuid]);

        return $s->refresh();
    }

    /**
     * Either side asks to end it, with notice: asked `cancel_notice_days` or more before the month ends, it ends with this month;
     * later, with the next one.
     *
     * @param  'client'|'freelancer'  $side
     */
    public function cancel(Sub $sub, string $side): Sub
    {
        $s = DB::transaction(function () use ($sub, $side) {
            $s = Sub::query()->whereKey($sub->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== Sub::ACTIVE) {
                throw $this->state($s);
            }
            if ($s->cancel_requested_at !== null) {
                throw new FreelanceException('subscription_cancel_already', 409);
            }
            $end = $s->period_ends_at->copy();
            $endsAt = now()->addDays(self::noticeDays())->lte($end) ? $end : $end->addDays(self::periodDays());
            $s->fill(['cancel_requested_at' => now(), 'cancel_by' => $side, 'ends_at' => $endsAt])->save();

            return $s;
        });

        $other = $side === 'client' ? $s->freelancer : $s->client;
        $this->notifier->notify($other, 'retainer.cancel_requested', ['service' => (string) ($s->snapshot['title'] ?? ''), 'date' => $this->day($s->ends_at)], ['subscription' => $s->uuid]);

        return $s;
    }

    /** The one who asked to end it takes it back (before it ended). */
    public function keep(Sub $sub, string $side): Sub
    {
        $s = DB::transaction(function () use ($sub, $side) {
            $s = Sub::query()->whereKey($sub->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== Sub::ACTIVE || $s->cancel_requested_at === null || $s->cancel_by !== $side) {
                throw new FreelanceException('subscription_keep_invalid', 409);
            }
            $s->fill(['cancel_requested_at' => null, 'cancel_by' => null, 'ends_at' => null])->save();

            return $s;
        });

        $other = $side === 'client' ? $s->freelancer : $s->client;
        $this->notifier->notify($other, 'retainer.kept', ['service' => (string) ($s->snapshot['title'] ?? '')], ['subscription' => $s->uuid]);

        return $s;
    }

    /**
     * Hourly: the client is reminded once when confirming the next month opens; a subscription reaching its asked end, or whose month
     * ran out (with the grace) without the next one confirmed, ends — both are told and the freelancer's place frees.
     *
     * @return array{reminded: int, ended: int}
     */
    public function sweep(): array
    {
        $reminded = 0;
        Sub::query()->where('status', Sub::ACTIVE)->whereNull('renew_reminded_at')
            ->where('period_ends_at', '<=', now()->addDays(self::renewWindowDays()))->with(['client'])->each(function (Sub $s) use (&$reminded): void {
                if ($s->endsWithThisMonth()) {
                    return;
                }
                $s->forceFill(['renew_reminded_at' => now()])->save();
                $this->notifier->notify($s->client, 'retainer.renew_due', ['service' => (string) ($s->snapshot['title'] ?? ''), 'date' => $this->day($s->period_ends_at)], ['subscription' => $s->uuid]);
                $reminded++;
            });

        $ended = 0;
        Sub::query()->where('status', Sub::ACTIVE)->where(fn ($q) => $q->where('ends_at', '<=', now())->orWhere('period_ends_at', '<=', now()->subDays(self::graceDays())))
            ->each(function (Sub $sub) use (&$ended): void {
                $s = DB::transaction(function () use ($sub) {
                    $s = Sub::query()->whereKey($sub->id)->lockForUpdate()->first();
                    if ($s === null || $s->status !== Sub::ACTIVE) {
                        return null;
                    }
                    $asked = $s->ends_at !== null && $s->ends_at->lte(now());
                    if (! $asked && $s->period_ends_at->copy()->addDays(self::graceDays())->gt(now())) {
                        return null;
                    }
                    $s->fill(['status' => Sub::ENDED, 'ended_at' => now(), 'end_reason' => $asked ? 'cancelled_'.$s->cancel_by : 'not_renewed'])->save();

                    return $s;
                });
                if ($s === null) {
                    return;
                }
                $vars = ['service' => (string) ($s->snapshot['title'] ?? '')];
                $this->notifier->notify($s->client, 'retainer.ended', $vars, ['subscription' => $s->uuid]);
                $this->notifier->notify($s->freelancer, 'retainer.ended', $vars, ['subscription' => $s->uuid]);
                app(FreelanceCapacity::class)->promote((int) $s->freelancer_id);
                $ended++;
            });

        return ['reminded' => $reminded, 'ended' => $ended];
    }

    // -------------------------------------------------------------- lists

    public function forClient(User $client, string $uuid): Sub
    {
        return Sub::query()->where('client_id', $client->id)->where('uuid', $uuid)->with(['service', 'freelancer', 'currentOrder.agreement'])->firstOrFail();
    }

    public function forFreelancer(Freelancer $freelancer, string $uuid): Sub
    {
        return Sub::query()->where('freelancer_id', $freelancer->id)->where('uuid', $uuid)->with(['service', 'client', 'currentOrder.agreement'])->firstOrFail();
    }

    /** @param  'client'|'freelancer'|'staff'  $side */
    public function page(string $side, ?int $personId, ?string $status, int $perPage, ?string $search = null): LengthAwarePaginator
    {
        return Sub::query()
            ->when($side !== 'staff', fn ($q) => $q->where($side === 'client' ? 'client_id' : 'freelancer_id', $personId))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($search !== null, function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->whereHas('service', fn ($s) => $s->withTrashed()->where('title', 'like', $term))
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term))
                    ->orWhereHas('freelancer', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term)));
            })
            ->with(['service', 'client', 'freelancer', 'currentOrder.agreement'])
            ->orderByRaw("case status when 'active' then 0 when 'pending' then 1 else 2 end")->latest('id')->paginate($perPage);
    }

    /**
     * @param  'client'|'freelancer'|'staff'  $side  each sees the money from their side
     * @return array<string, mixed>
     */
    public function present(Sub $s, string $side): array
    {
        $snap = (array) $s->snapshot;
        $current = $s->currentOrder;
        $money = (array) ($current?->agreement?->money ?? []);
        $open = $this->renewOpen($s);

        return [
            'uuid' => $s->uuid,
            'status' => $s->status,
            'end_reason' => $s->end_reason,
            'service' => ['uuid' => $s->service?->uuid, 'title' => $snap['title'] ?? $s->service?->title],
            'level' => $s->level,
            'level_label' => __('freelance.service_levels.'.$s->level),
            'tier_name' => $snap['tier']['name'] ?? null,
            'features' => array_values((array) ($snap['tier']['features'] ?? [])),
            'addons' => array_values((array) ($snap['addons'] ?? [])),
            // Every month, at the terms kept from the start.
            'price_minor' => $s->price_minor,
            'currency' => $s->currency_code,
            'decimals' => (int) ($snap['decimals'] ?? 2),
            'delivery_days' => $s->delivery_days,
            'revisions' => $s->revisions,
            'money' => match ($side) {
                'client' => ['price_minor' => $s->price_minor, 'client_fee_minor' => $money['client_fee_minor'] ?? 0, 'total_minor' => $money['client_total_minor'] ?? $s->price_minor],
                'freelancer' => ['price_minor' => $s->price_minor, 'commission_minor' => $money['commission_minor'] ?? 0, 'net_minor' => $money['net_minor'] ?? $s->price_minor],
                default => $money,
            },
            'cycle' => $s->cycle,
            'started_at' => $s->started_at?->toIso8601String(),
            'period_ends_at' => $s->period_ends_at?->toIso8601String(),
            // Confirming the next month: from when, until when, and whether it can be done now.
            'renew' => $s->status !== Sub::ACTIVE || $s->endsWithThisMonth() ? null : [
                'opens_at' => $s->period_ends_at->copy()->subDays(self::renewWindowDays())->toIso8601String(),
                'due_by' => $s->period_ends_at->copy()->addDays(self::graceDays())->toIso8601String(),
                'open' => $open,
            ],
            'cancel' => $s->cancel_requested_at === null ? null : ['by' => $s->cancel_by, 'requested_at' => $s->cancel_requested_at->toIso8601String(), 'ends_at' => $s->ends_at?->toIso8601String()],
            'notice_days' => self::noticeDays(),
            // The month running now (an order of its own).
            'current' => $current === null ? null : ['uuid' => $current->uuid, 'cycle' => $current->cycle, 'status' => $current->status(), 'project' => $current->project_id === null ? null : \Modules\Freelance\Models\FreelanceProject::query()->whereKey($current->project_id)->value('uuid')],
            'client' => $side === 'client' ? null : ['name' => $s->client?->name] + ($side === 'staff' ? ['id' => $s->client_id, 'phone' => $s->client?->phone] : []),
            'freelancer' => $side === 'freelancer' ? null : ['uuid' => $s->freelancer?->uuid, 'name' => $s->freelancer?->name] + ($side === 'staff' ? ['id' => $s->freelancer_id] : []),
            'can' => match ($side) {
                'client' => ['renew' => $open, 'cancel' => $s->status === Sub::ACTIVE && $s->cancel_requested_at === null, 'keep' => $s->status === Sub::ACTIVE && $s->cancel_by === 'client'],
                'freelancer' => ['cancel' => $s->status === Sub::ACTIVE && $s->cancel_requested_at === null, 'keep' => $s->status === Sub::ACTIVE && $s->cancel_by === 'freelancer'],
                default => new \stdClass,
            },
            'ended_at' => $s->ended_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------- inside

    private function renewOpen(Sub $s): bool
    {
        if ($s->status !== Sub::ACTIVE || $s->period_ends_at === null || $s->endsWithThisMonth()) {
            return false;
        }

        return now()->gte($s->period_ends_at->copy()->subDays(self::renewWindowDays())) && now()->lte($s->period_ends_at->copy()->addDays(self::graceDays()));
    }

    private function state(Sub $s): FreelanceException
    {
        return new FreelanceException('subscription_invalid_state', 409, ['status' => __('freelance.subscription_statuses.'.$s->status)], ['status' => $s->status]);
    }

    private function day(?Carbon $at): string
    {
        return $at?->translatedFormat('j F Y') ?? '';
    }
}
