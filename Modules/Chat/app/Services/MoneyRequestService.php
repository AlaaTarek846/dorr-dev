<?php

namespace Modules\Chat\Services;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\User\Models\User;
use Modules\Wallet\Services\TransferRecipientResolver;
use Modules\Wallet\Services\TransferService;

/**
 * Money in the chat, beyond sharing a receipt:
 *  - a **money request** ("send me 50") in a direct chat — the other person pays it with their PIN;
 *  - a **bill split** in a group (or a direct chat) — a total shared between chosen members,
 *    equally or by hand, each paying their own share.
 *
 * Paying is a normal wallet transfer to the requester (TransferService: limits, fees, balance,
 * the lot) with an idempotency key tied to the request and the payer, so a double tap or a retry
 * can never pay twice. The message only records what happened (status, transaction).
 */
class MoneyRequestService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly TransferService $transfers,
        private readonly TransferRecipientResolver $recipients,
    ) {}

    // ---------------------------------------------------------------- building (on send)

    /**
     * @param  array<string, mixed>  $data  amount_minor
     * @return array<string, mixed>
     */
    public function requestMeta(Model $me, ChatConversation $conversation, array $data): array
    {
        if ($conversation->isGroup()) {
            throw new ChatException('money_request_direct_only', 422);
        }

        return ['amount_minor' => $this->amount($data['amount_minor'] ?? null), 'status' => 'pending'] + $this->currency($me);
    }

    /**
     * Shares are keyed by account ("user:7"). Equal: the total divided, the leftover minor units
     * going to the first people so it always adds up. Custom: the amounts given, which must add up.
     * The requester's own share (if they're in it) counts as paid.
     *
     * @param  array<string, mixed>  $data  amount_minor, split_mode, split_participants[] | split_shares[{participant_id, amount_minor}]
     * @return array<string, mixed>
     */
    public function splitMeta(Model $me, ChatConversation $conversation, ChatParticipant $mine, array $data): array
    {
        $total = $this->amount($data['amount_minor'] ?? null);
        $members = $conversation->activeParticipants()->get()->keyBy('id');
        $mode = ($data['split_mode'] ?? 'equal') === 'custom' ? 'custom' : 'equal';

        if ($mode === 'equal') {
            $ids = collect((array) ($data['split_participants'] ?? []))->map(fn ($id) => (int) $id)->unique()
                ->filter(fn ($id) => $members->has($id))->values();
            if ($ids->count() < 2 || $ids->count() > 100) {
                throw new ChatException('split_invalid', 422);
            }
            $base = intdiv($total, $ids->count());
            $left = $total - $base * $ids->count();
            $shares = $ids->map(function (int $id, int $i) use ($base, $left) {
                return ['participant_id' => $id, 'amount_minor' => $base + ($i < $left ? 1 : 0)];
            });
        } else {
            $shares = collect((array) ($data['split_shares'] ?? []))
                ->map(fn ($s) => ['participant_id' => (int) ($s['participant_id'] ?? 0), 'amount_minor' => (int) ($s['amount_minor'] ?? 0)])
                ->filter(fn ($s) => $members->has($s['participant_id']) && $s['amount_minor'] > 0)
                ->unique('participant_id')->values();
            if ($shares->count() < 2 || $shares->count() > 100 || $shares->sum('amount_minor') !== $total) {
                throw new ChatException('split_invalid', 422);
            }
        }

        if ($shares->where('participant_id', '!=', $mine->id)->isEmpty()) {
            throw new ChatException('split_invalid', 422);
        }

        return [
            'total_minor' => $total,
            'mode' => $mode,
            'shares' => $shares->map(fn ($s) => [
                'key' => $members->get($s['participant_id'])->key(),
                'amount_minor' => $s['amount_minor'],
                // Mine is "paid" by definition: I'm the one being paid back.
                'status' => $s['participant_id'] === $mine->id ? 'owner' : 'pending',
                'transaction_id' => null,
                'paid_at' => null,
            ])->all(),
            'status' => 'open',
        ] + $this->currency($me);
    }

    // ---------------------------------------------------------------- paying

    /**
     * Pay a money request (or my share of a split). The PIN was checked by RequiresWalletPin.
     */
    public function pay(User $me, ChatMessage $message): ChatMessage
    {
        $participant = $this->conversations->participantOf($me, $message->conversation, true);
        $this->assertPayable($message);

        $requester = $message->sender_type === 'user' ? User::query()->find($message->sender_id) : null;
        if ($requester === null || $message->isFrom($participant->participant_type, (int) $participant->participant_id)) {
            throw new ChatException('money_request_not_yours_to_pay', 403);
        }

        $country = Country::query()->find(data_get($message->meta, 'country_id')) ?? throw new ChatException('money_request_closed', 422);
        $amount = $this->amountDue($message, $participant);

        // One key per (request, payer): replaying it returns the first transfer, never a second one.
        $key = 'chatpay:'.$message->uuid.':'.$participant->key();
        $token = $this->recipients->tokenFor($me, $country, $requester);
        $result = $this->transfers->send($me, $country, $token, $amount, null, $key);

        DB::transaction(function () use ($message, $participant, $result) {
            $locked = ChatMessage::query()->lockForUpdate()->findOrFail($message->id);
            $meta = (array) $locked->meta;
            $paid = ['transaction_id' => $result['out']->uuid, 'paid_at' => now()->toIso8601String()];

            if ($locked->type === MessageType::MoneyRequest) {
                $meta = array_merge($meta, ['status' => 'paid', 'paid_by' => $participant->key()], $paid);
            } else {
                foreach ($meta['shares'] as $i => $share) {
                    if ($share['key'] === $participant->key()) {
                        $meta['shares'][$i] = array_merge($share, ['status' => 'paid'], $paid);
                    }
                }
                if (collect($meta['shares'])->every(fn ($s) => in_array($s['status'], ['paid', 'owner', 'declined'], true))) {
                    $meta['status'] = 'settled';
                }
            }

            $locked->forceFill(['meta' => $meta])->save();
            $message->setRawAttributes($locked->getAttributes());
        });

        app(MessageService::class)->rebroadcast($message);

        return $message;
    }

    /**
     * "Send money" straight from a one-to-one chat (no request first): a normal wallet transfer to
     * the other person (PIN checked by RequiresWalletPin), then its receipt posted in the chat.
     * `$uuid` is the message's id and the transfer's idempotency key at once: a retry after a
     * dropped connection returns the same transfer and the same message — never a second payment.
     */
    public function sendMoney(User $me, ChatConversation $conversation, int $amountMinor, ?string $note, string $uuid, ?string $gift = null): ChatMessage
    {
        $participant = $this->conversations->participantOf($me, $conversation, true);

        if ($conversation->isGroup() || $conversation->isSelf()) {
            throw new ChatException('money_request_direct_only', 422);
        }
        if ($conversation->status === ConversationStatus::Rejected) {
            throw ChatException::requestRejected();
        }

        $peer = $conversation->activeParticipants()->where('id', '!=', $participant->id)->first()?->participant();
        if (! $peer instanceof User) {
            throw ChatException::userNotFound();
        }
        // Blocked either way: no money moves (checked before, not after, the transfer).
        app(ChatPrivacy::class)->assertReachable($me, $peer);

        $country = currentCountry() ?? throw new ChatException('money_request_closed', 422);
        $amount = $this->amount($amountMinor);

        $token = $this->recipients->tokenFor($me, $country, $peer);
        $result = $this->transfers->send($me, $country, $token, $amount, null, 'chatsend:'.$uuid);

        return app(MessageService::class)->send($me, $conversation, [
            'type' => MessageType::WalletTransfer->value,
            'wallet_transaction_id' => $result['out']->uuid,
            'body' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'uuid' => $uuid,
            'gift' => $gift,
        ]);
    }

    /**
     * "Not paying this one": the request (or my share) is marked declined.
     */
    public function decline(Model $me, ChatMessage $message): ChatMessage
    {
        $participant = $this->conversations->participantOf($me, $message->conversation, true);
        $this->assertPayable($message);
        $this->amountDue($message, $participant); // it must be mine to answer, and still open

        $this->updateMeta($message, function (array $meta) use ($message, $participant) {
            if ($message->type === MessageType::MoneyRequest) {
                return array_merge($meta, ['status' => 'declined', 'declined_by' => $participant->key()]);
            }
            foreach ($meta['shares'] as $i => $share) {
                if ($share['key'] === $participant->key()) {
                    $meta['shares'][$i]['status'] = 'declined';
                }
            }

            return $meta;
        });

        return $message;
    }

    /**
     * The requester calls it off: nothing more can be paid (what was paid stays paid).
     */
    public function cancel(Model $me, ChatMessage $message): ChatMessage
    {
        $participant = $this->conversations->participantOf($me, $message->conversation, true);
        $this->assertPayable($message);

        if (! $message->isFrom($participant->participant_type, (int) $participant->participant_id)) {
            throw ChatException::notYourMessage();
        }

        $this->updateMeta($message, fn (array $meta) => array_merge($meta, ['status' => 'cancelled']));

        return $message;
    }

    // ---------------------------------------------------------------- reading

    /**
     * What the apps draw, for one viewer.
     *
     * @return array<string, mixed>|null
     */
    public static function present(ChatMessage $m, ChatParticipant $viewer, callable $profile): ?array
    {
        $meta = (array) $m->meta;
        $mineToAsk = $m->isFrom($viewer->participant_type, (int) $viewer->participant_id);
        $currency = ['currency' => $meta['currency'] ?? null, 'currency_symbol' => $meta['currency_symbol'] ?? null, 'decimal_places' => (int) ($meta['decimal_places'] ?? 2)];

        if ($m->type === MessageType::MoneyRequest) {
            return [
                'kind' => 'request',
                'amount_minor' => (int) ($meta['amount_minor'] ?? 0),
                'status' => $meta['status'] ?? 'pending',
                'paid_at' => $meta['paid_at'] ?? null,
                'is_requester' => $mineToAsk,
                'can_pay' => ! $mineToAsk && ($meta['status'] ?? null) === 'pending',
                'can_cancel' => $mineToAsk && ($meta['status'] ?? null) === 'pending',
            ] + $currency;
        }

        $shares = collect($meta['shares'] ?? []);
        $myShare = $shares->firstWhere('key', $viewer->key());
        $open = ($meta['status'] ?? null) === 'open';

        return [
            'kind' => 'split',
            'total_minor' => (int) ($meta['total_minor'] ?? 0),
            'mode' => $meta['mode'] ?? 'equal',
            'status' => $meta['status'] ?? 'open',
            'is_requester' => $mineToAsk,
            'shares' => $shares->map(function ($s) use ($profile) {
                [$type, $id] = explode(':', (string) $s['key'], 2) + [null, null];

                return ['profile' => $profile($type, $id), 'amount_minor' => (int) $s['amount_minor'], 'status' => $s['status'], 'paid_at' => $s['paid_at'] ?? null];
            })->values()->all(),
            'paid_minor' => (int) $shares->whereIn('status', ['paid', 'owner'])->sum('amount_minor'),
            'my_share' => $myShare === null ? null : ['amount_minor' => (int) $myShare['amount_minor'], 'status' => $myShare['status']],
            'can_pay' => $open && ! $mineToAsk && ($myShare['status'] ?? null) === 'pending',
            'can_cancel' => $open && $mineToAsk,
        ] + $currency;
    }

    // ---------------------------------------------------------------- helpers

    private function amountDue(ChatMessage $message, ChatParticipant $participant): int
    {
        $meta = (array) $message->meta;

        if ($message->type === MessageType::MoneyRequest) {
            if (($meta['status'] ?? null) !== 'pending' || $message->isFrom($participant->participant_type, (int) $participant->participant_id)) {
                throw new ChatException('money_request_closed', 422);
            }

            return (int) $meta['amount_minor'];
        }

        $share = collect($meta['shares'] ?? [])->firstWhere('key', $participant->key());
        if (($meta['status'] ?? null) !== 'open' || $share === null || $share['status'] !== 'pending') {
            throw new ChatException('money_request_closed', 422);
        }

        return (int) $share['amount_minor'];
    }

    private function assertPayable(ChatMessage $message): void
    {
        if (! in_array($message->type, [MessageType::MoneyRequest, MessageType::BillSplit], true) || $message->isGone()) {
            throw ChatException::messageDeleted();
        }
    }

    private function updateMeta(ChatMessage $message, callable $change): void
    {
        DB::transaction(function () use ($message, $change) {
            $locked = ChatMessage::query()->lockForUpdate()->findOrFail($message->id);
            $locked->forceFill(['meta' => $change((array) $locked->meta)])->save();
            $message->setRawAttributes($locked->getAttributes());
        });

        app(MessageService::class)->rebroadcast($message);
    }

    private function amount(mixed $value): int
    {
        $amount = (int) $value;

        if ($amount < 1 || $amount > 100_000_000_00) {
            throw new ChatException('money_request_amount', 422);
        }

        return $amount;
    }

    /**
     * The request is in the requester's money: their current country's currency.
     *
     * @return array<string, mixed>
     */
    private function currency(Model $me): array
    {
        $country = currentCountry() ?? throw new ChatException('money_request_closed', 422);
        $currency = $country->currency;

        return [
            'country_id' => $country->id,
            'country_code' => $country->code,
            'currency' => $currency?->code,
            'currency_symbol' => $currency?->symbol,
            'decimal_places' => $currency?->decimal_places ?? 2,
        ];
    }
}
