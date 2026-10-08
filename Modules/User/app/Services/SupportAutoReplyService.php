<?php

namespace Modules\User\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\User\Models\SupportMessage;
use Modules\User\Models\SupportSetting;
use Modules\User\Models\SupportTicket;
use Throwable;

/**
 * The replies support sends on its own, while no person has answered yet:
 *
 *  - **Acknowledgement** — once, when a ticket opens: "we received ticket #12…".
 *  - **Away note** — outside working hours instead of the acknowledgement, and again at most once per
 *    `away_every_hours` while the customer keeps writing.
 *  - **FAQ answer** — the AI module picks the FAQ that answers the ticket and rewrites it for this customer
 *    ({@see SupportFaqAnswerer}); at most `ai_max_replies` per ticket, never for money / fraud / complaints /
 *    legal or medical questions / a request for a person. It runs after the response is sent (the customer
 *    never waits for the AI), and arrives live.
 *
 * Every one is marked automatic (`sender = system`, `is_auto`), never in an agent's name, and none is sent
 * once an agent has replied, on a ticket that is resolved / closed, or after the customer asked for a person.
 * A failure here never fails the customer's own message.
 */
class SupportAutoReplyService
{
    public function __construct(
        private readonly SupportFaqAnswerer $answerer,
        private readonly SupportNotifier $notifier,
    ) {}

    /** A ticket was just opened with its first message. */
    public function ticketOpened(SupportTicket $ticket, SupportMessage $first): void
    {
        $this->safely(function () use ($ticket, $first) {
            $settings = SupportSetting::current();

            if (! $this->eligible($ticket, $settings)) {
                return;
            }

            if ($this->awayNow($settings)) {
                $this->post($ticket, SupportSetting::KIND_AWAY, $this->text($settings, SupportSetting::KIND_AWAY, $ticket));
            } elseif ($settings->ack_enabled) {
                $this->post($ticket, SupportSetting::KIND_ACK, $this->text($settings, SupportSetting::KIND_ACK, $ticket));
            }

            $this->answerLater($ticket, $first, trim($ticket->title."\n".$first->body));
        });
    }

    /** The customer wrote again in an open ticket. */
    public function customerWrote(SupportTicket $ticket, SupportMessage $message): void
    {
        $this->safely(function () use ($ticket, $message) {
            $settings = SupportSetting::current();

            if (! $this->eligible($ticket, $settings)) {
                return;
            }

            if ($this->awayNow($settings) && ! $this->awayToldRecently($ticket, $settings)) {
                $this->post($ticket, SupportSetting::KIND_AWAY, $this->text($settings, SupportSetting::KIND_AWAY, $ticket));
            }

            $this->answerLater($ticket, $message, trim((string) $message->body));
        });
    }

    /**
     * The FAQ answer, once the customer has their response (see answerLater). Everything is checked again:
     * an agent may have replied, or the customer asked for a person, in the meantime.
     */
    public function answerFromFaqs(int $ticketId, string $question): void
    {
        $this->safely(function () use ($ticketId, $question) {
            $ticket = SupportTicket::query()->with('user')->find($ticketId);
            $settings = SupportSetting::current();

            if ($ticket === null || ! $settings->ai_enabled || ! $this->eligible($ticket, $settings)) {
                return;
            }

            $sent = $ticket->messages()->where('is_auto', true)->where('auto_kind', SupportSetting::KIND_FAQ)->count();

            if ($sent >= max(0, $settings->ai_max_replies)) {
                return;
            }

            $answer = $this->answerer->answer($question, $this->locale($ticket));

            if ($answer !== null) {
                $this->post($ticket, SupportSetting::KIND_FAQ, $answer['answer'], push: true);
            }
        });
    }

    /**
     * No automatic reply is due on this ticket any more.
     */
    public function eligible(SupportTicket $ticket, ?SupportSetting $settings = null): bool
    {
        $settings ??= SupportSetting::current();

        return $settings->auto_reply_enabled
            && $ticket->status->acceptsReplies()
            && $ticket->auto_reply_stopped_at === null
            // Once a person has answered, the conversation is theirs.
            && ! $ticket->messages()->where('sender', SupportMessage::SENDER_SUPPORT)->exists();
    }

    // ---------------------------------------------------------------- internals

    private function answerLater(SupportTicket $ticket, SupportMessage $message, string $question): void
    {
        if (! SupportSetting::current()->ai_enabled || $question === '' || $message->body === null) {
            return;
        }

        $ticketId = (int) $ticket->getKey();

        // After the response: the AI takes seconds, the customer's message must not wait for it.
        defer(fn () => $this->answerFromFaqs($ticketId, $question));
    }

    private function post(SupportTicket $ticket, string $kind, string $body, bool $push = false): void
    {
        if (trim($body) === '') {
            return;
        }

        $message = DB::transaction(function () use ($ticket, $kind, $body) {
            $message = $ticket->messages()->create([
                'user_id' => $ticket->user_id,
                'sender' => SupportMessage::SENDER_SYSTEM,
                'body' => $body,
                'is_auto' => true,
                'auto_kind' => $kind,
            ]);

            $ticket->forceFill(['last_message_at' => now()])->save();

            return $message;
        });

        $this->notifier->autoReplied($ticket->refresh(), $message, $push);
    }

    private function awayNow(SupportSetting $settings): bool
    {
        return $settings->away_enabled && ! $settings->isOpenAt(now());
    }

    private function awayToldRecently(SupportTicket $ticket, SupportSetting $settings): bool
    {
        return $ticket->messages()
            ->where('is_auto', true)
            ->where('auto_kind', SupportSetting::KIND_AWAY)
            ->where('created_at', '>=', now()->subHours(max(1, $settings->away_every_hours)))
            ->exists();
    }

    private function text(SupportSetting $settings, string $kind, SupportTicket $ticket): string
    {
        $locale = $this->locale($ticket);

        return $settings->textFor($kind, $locale, ['id' => $ticket->number, 'hours' => $settings->hoursText($locale)]);
    }

    /** The customer's own language (the one their app uses), else the site's. */
    private function locale(SupportTicket $ticket): string
    {
        $locale = (string) ($ticket->user?->locale ?: config('app.locale', 'ar'));

        return in_array($locale, ['ar', 'en'], true) ? $locale : 'en';
    }

    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
            Log::warning('[SupportAutoReply] '.$e->getMessage());
        }
    }
}
