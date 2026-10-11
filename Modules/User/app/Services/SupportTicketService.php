<?php

namespace Modules\User\Services;

use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\Admin;
use Modules\User\Enums\SupportTicketStatus;
use Modules\User\Http\Resources\SupportMessageResource;
use Modules\User\Http\Resources\SupportTicketResource;
use Modules\User\Models\SupportMessage;
use Modules\User\Models\SupportTicket;
use Modules\User\Models\User;

/**
 * The life of a support ticket: the customer opens it and writes, an agent answers and moves its
 * status, either side can finish or reopen it, and everyone is told live (see {@see SupportNotifier}).
 *
 * Status rules: opened and reopened tickets take messages; resolved and closed ones do not until
 * somebody reopens them. The customer can close or reopen their own ticket; an agent can set any
 * status. Every move is written to the ticket's history.
 */
class SupportTicketService
{
    public function __construct(
        private readonly SupportNotifier $notifier,
        private readonly SupportAutoReplyService $autoReplies,
    ) {}

    public function open(User $user, string $title, string $body, ?UploadedFile $image): SupportTicket
    {
        $first = null;

        $ticket = DB::transaction(function () use ($user, $title, $body, $image, &$first) {
            $path = $image?->store('support-tickets/'.$user->getKey(), 'public');

            $ticket = SupportTicket::query()->create([
                'user_id' => $user->getKey(),
                'title' => $title,
                'body' => $body,
                'image_path' => $path,
                'status' => SupportTicketStatus::Opened,
                'last_message_at' => now(),
            ]);

            // What the customer wrote is the first message of the conversation.
            $first = $ticket->messages()->create([
                'user_id' => $user->getKey(),
                'sender' => SupportMessage::SENDER_USER,
                'body' => $body,
                'image_path' => $path,
            ]);

            $ticket->activities()->create(['actor' => 'user', 'status' => SupportTicketStatus::Opened]);

            return $ticket;
        });

        $this->notifier->ticketOpened($ticket);
        // The acknowledgement (or the away note) right away, and the FAQ answer once the response is sent.
        $this->autoReplies->ticketOpened($ticket, $first);

        return $ticket->refresh()->load('latestMessage');
    }

    public function customerReply(User $user, SupportTicket $ticket, ?string $body, ?UploadedFile $image): SupportMessage
    {
        $this->assertAcceptsReplies($ticket);

        $message = $this->addMessage($ticket, [
            'user_id' => $user->getKey(),
            'sender' => SupportMessage::SENDER_USER,
        ], $body, $image, 'support-messages/'.$ticket->getKey());

        $this->notifier->customerReplied($ticket->refresh(), $message);
        $this->autoReplies->customerWrote($ticket, $message);

        return $message;
    }

    public function agentReply(Admin $admin, SupportTicket $ticket, ?string $body, ?UploadedFile $image): SupportMessage
    {
        $this->assertAcceptsReplies($ticket);

        $message = $this->addMessage($ticket, [
            'user_id' => $ticket->user_id,
            'admin_id' => $admin->getKey(),
            'sender' => SupportMessage::SENDER_SUPPORT,
        ], $body, $image, 'support-messages/'.$ticket->getKey());

        // The first agent to answer takes the ticket.
        if ($ticket->admin_id === null) {
            $ticket->forceFill(['admin_id' => $admin->getKey()])->save();
        }

        $this->notifier->agentReplied($ticket->refresh(), $message);

        return $message;
    }

    /**
     * The customer's answer to an automatic FAQ reply: "that solved it" closes the ticket; "I still need a
     * person" stops the automatic replies on it and tells the support team someone is waiting.
     */
    public function autoReplyFeedback(SupportTicket $ticket, bool $solved): SupportTicket
    {
        $this->assertAcceptsReplies($ticket);

        if ($solved) {
            return $this->move($ticket, SupportTicketStatus::Closed, null, 'user');
        }

        if ($ticket->auto_reply_stopped_at === null) {
            $ticket->forceFill(['auto_reply_stopped_at' => now()])->save();
            $this->notifier->customerWantsAgent($ticket->refresh());
        }

        return $ticket;
    }

    /**
     * The customer closes or reopens their own ticket (nothing else: resolving is up to support).
     */
    public function customerSetStatus(SupportTicket $ticket, SupportTicketStatus $to): SupportTicket
    {
        $allowed = match ($ticket->status) {
            SupportTicketStatus::Opened, SupportTicketStatus::Reopened => [SupportTicketStatus::Closed],
            SupportTicketStatus::Resolved, SupportTicketStatus::Closed => [SupportTicketStatus::Reopened],
        };

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [__('api.support_ticket_status_not_allowed')]]);
        }

        return $this->move($ticket, $to, null, 'user');
    }

    public function agentSetStatus(Admin $admin, SupportTicket $ticket, SupportTicketStatus $to): SupportTicket
    {
        if ($ticket->status === $to) {
            return $ticket;
        }

        // An agent who moves a ticket nobody had taken yet takes it.
        if ($ticket->admin_id === null) {
            $ticket->forceFill(['admin_id' => $admin->getKey()])->save();
        }

        return $this->move($ticket, $to, $admin, 'support');
    }

    // ---------------------------------------------------------------- internals

    private function move(SupportTicket $ticket, SupportTicketStatus $to, ?Admin $admin, string $actor): SupportTicket
    {
        DB::transaction(function () use ($ticket, $to, $admin, $actor) {
            $ticket->forceFill(['status' => $to])->save();
            $ticket->activities()->create(['actor' => $actor, 'admin_id' => $admin?->getKey(), 'status' => $to]);
        });

        $this->notifier->statusChanged($ticket->refresh(), $actor);

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function addMessage(SupportTicket $ticket, array $attributes, ?string $body, ?UploadedFile $image, string $folder): SupportMessage
    {
        $body = filled($body) ? trim($body) : null;

        if ($body === null && $image === null) {
            throw ValidationException::withMessages(['body' => [__('api.support_message_empty')]]);
        }

        return DB::transaction(function () use ($ticket, $attributes, $body, $image, $folder) {
            $message = $ticket->messages()->create($attributes + [
                'body' => $body,
                'image_path' => $image?->store($folder, 'public'),
            ]);

            $ticket->forceFill(['last_message_at' => now()])->save();

            return $message;
        });
    }

    private function assertAcceptsReplies(SupportTicket $ticket): void
    {
        if (! $ticket->status->acceptsReplies()) {
            throw ValidationException::withMessages(['ticket' => [__('api.support_ticket_closed')]]);
        }
    }

    // ---------------------------------------------------------------- responses

    public function ticketResponse(SupportTicket $ticket, string $message, int $status = 200): JsonResponse
    {
        return ApiResponse::success(new SupportTicketResource($ticket->loadMissing('latestMessage')), $message, $status);
    }

    public function messageResponse(SupportMessage $message, string $text): JsonResponse
    {
        return ApiResponse::success(new SupportMessageResource($message->loadMissing('admin')), $text, 201);
    }
}
