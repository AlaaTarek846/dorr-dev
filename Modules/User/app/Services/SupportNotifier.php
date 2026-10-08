<?php

namespace Modules\User\Services;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Collection;
use Modules\Admin\Models\Admin;
use Modules\User\Events\SupportRealtimeEvent;
use Modules\User\Http\Resources\AdminSupportTicketResource;
use Modules\User\Http\Resources\SupportMessageResource;
use Modules\User\Http\Resources\SupportTicketResource;
use Modules\User\Models\SupportMessage;
use Modules\User\Models\SupportTicket;
use Modules\User\Models\User;

/**
 * Tells both sides of a support ticket what just happened, three ways at once:
 *
 *  - **Live** (Pusher): the open conversation / list in the app and the dashboard updates itself
 *    ({@see SupportRealtimeEvent}).
 *  - **Notification** (in-app list + live toast, in the reader's language) through NotificationCenter.
 *  - **Push** (OneSignal) to the customer's phone, in every language, for when the app is closed.
 *
 * Best effort: a notification problem never fails the reply or the status change that caused it.
 */
class SupportNotifier
{
    public function __construct(private readonly NotificationCenter $center) {}

    /** A customer opened a new ticket: the support team hears about it. */
    public function ticketOpened(SupportTicket $ticket): void
    {
        $ticket->loadMissing(['user', 'admin', 'latestMessage']);
        $admins = $this->supportAdmins();

        $this->toAdmins(SupportRealtimeEvent::TICKET_CREATED, $admins, ['ticket' => $this->forAdmin($ticket)]);
        $this->center->send(
            $admins,
            'support.ticket.created',
            'support_ticket_new_title',
            'support_ticket_new_body',
            ['id' => $ticket->number, 'name' => $this->customerName($ticket)],
            ['type' => 'support', 'ticket_id' => $ticket->id],
            push: false,
        );
    }

    /** The customer wrote in a ticket: the agent who has it (or, unassigned, every agent) is told. */
    public function customerReplied(SupportTicket $ticket, SupportMessage $message): void
    {
        $ticket->loadMissing(['user', 'admin', 'latestMessage']);
        $admins = $this->supportAdmins();
        $audience = $ticket->admin !== null ? collect([$ticket->admin]) : $admins;

        $this->toAdmins(SupportRealtimeEvent::MESSAGE, $admins, ['ticket' => $this->forAdmin($ticket), 'message' => $this->message($message)]);
        $this->toCustomer($ticket, SupportRealtimeEvent::MESSAGE, ['ticket' => $this->forCustomer($ticket), 'message' => $this->message($message)]);
        $this->center->send(
            $audience,
            'support.ticket.customer_reply',
            'support_ticket_customer_reply_title',
            'support_ticket_customer_reply_body',
            ['id' => $ticket->number, 'name' => $this->customerName($ticket)],
            ['type' => 'support', 'ticket_id' => $ticket->id],
            push: false,
        );
    }

    /** An agent answered: the customer gets a push and the live message. */
    public function agentReplied(SupportTicket $ticket, SupportMessage $message): void
    {
        $ticket->loadMissing(['user', 'admin', 'latestMessage']);

        $this->toCustomer($ticket, SupportRealtimeEvent::MESSAGE, ['ticket' => $this->forCustomer($ticket), 'message' => $this->message($message)]);
        $this->toAdmins(SupportRealtimeEvent::MESSAGE, $this->supportAdmins(), ['ticket' => $this->forAdmin($ticket), 'message' => $this->message($message)]);
        $this->center->send(
            $ticket->user,
            'support.ticket.reply',
            'support_ticket_reply_title',
            'support_ticket_reply_body',
            ['id' => $ticket->number, 'title' => $ticket->title],
            ['type' => 'support', 'ticket_id' => $ticket->id],
        );
    }

    /**
     * The status moved. Whoever did not do it is told (the customer by push when support did it,
     * the agent when the customer closed or reopened their own ticket).
     */
    public function statusChanged(SupportTicket $ticket, string $by): void
    {
        $ticket->loadMissing(['user', 'admin', 'latestMessage']);
        $admins = $this->supportAdmins();

        $this->toCustomer($ticket, SupportRealtimeEvent::TICKET_UPDATED, ['ticket' => $this->forCustomer($ticket)]);
        $this->toAdmins(SupportRealtimeEvent::TICKET_UPDATED, $admins, ['ticket' => $this->forAdmin($ticket)]);

        $status = $ticket->status->value;
        $data = ['type' => 'support', 'ticket_id' => $ticket->id, 'status' => $status];

        if ($by === 'support') {
            $this->center->send(
                $ticket->user,
                'support.ticket.status',
                'support_ticket_status_title',
                'support_ticket_status_'.$status.'_body',
                ['id' => $ticket->number, 'title' => $ticket->title],
                $data,
            );

            return;
        }

        $this->center->send(
            $ticket->admin !== null ? collect([$ticket->admin]) : $admins,
            'support.ticket.status',
            'support_ticket_customer_status_title',
            'support_ticket_customer_status_'.$status.'_body',
            ['id' => $ticket->number, 'name' => $this->customerName($ticket)],
            $data,
            push: false,
        );
    }

    /**
     * An automatic reply was posted (acknowledgement, away note, FAQ answer): live to both sides. Only the
     * FAQ answer is pushed to the customer's phone — the other two arrive while they are still on the ticket.
     */
    public function autoReplied(SupportTicket $ticket, SupportMessage $message, bool $push = false): void
    {
        $ticket->loadMissing(['user', 'admin', 'latestMessage']);

        $this->toCustomer($ticket, SupportRealtimeEvent::MESSAGE, ['ticket' => $this->forCustomer($ticket), 'message' => $this->message($message)]);
        $this->toAdmins(SupportRealtimeEvent::MESSAGE, $this->supportAdmins(), ['ticket' => $this->forAdmin($ticket), 'message' => $this->message($message)]);

        if ($push) {
            $this->center->send(
                $ticket->user,
                'support.ticket.auto_reply',
                'support_ticket_auto_title',
                'support_ticket_auto_body',
                ['id' => $ticket->number, 'title' => $ticket->title],
                ['type' => 'support', 'ticket_id' => $ticket->id],
            );
        }
    }

    /** The customer read the automatic answer and still wants a person: the support team is told. */
    public function customerWantsAgent(SupportTicket $ticket): void
    {
        $ticket->loadMissing(['user', 'admin', 'latestMessage']);
        $admins = $this->supportAdmins();

        $this->toCustomer($ticket, SupportRealtimeEvent::TICKET_UPDATED, ['ticket' => $this->forCustomer($ticket)]);
        $this->toAdmins(SupportRealtimeEvent::TICKET_UPDATED, $admins, ['ticket' => $this->forAdmin($ticket)]);
        $this->center->send(
            $ticket->admin !== null ? collect([$ticket->admin]) : $admins,
            'support.ticket.wants_agent',
            'support_ticket_wants_agent_title',
            'support_ticket_wants_agent_body',
            ['id' => $ticket->number, 'name' => $this->customerName($ticket)],
            ['type' => 'support', 'ticket_id' => $ticket->id],
            push: false,
        );
    }

    /**
     * A customer ended the app's guided help with "I need an agent" (a ticket usually follows): the support team
     * is told on the dashboard (in-app list + live toast) which topic they got stuck on.
     */
    public function helpAskedForAgent(User $user, ?string $topic): void
    {
        $this->center->send(
            $this->supportAdmins(),
            'support.help.agent',
            'support_help_agent_title',
            'support_help_agent_body',
            ['name' => (string) ($user->name ?: $user->phone ?: '#'.$user->id), 'topic' => $topic ?: '-'],
            ['type' => 'support_help', 'user_id' => $user->id],
            push: false,
        );
    }

    // ---------------------------------------------------------------- plumbing

    /**
     * @return Collection<int, Admin>
     */
    private function supportAdmins()
    {
        return $this->center->adminsWith('support-tickets.view');
    }

    /**
     * @param  Collection<int, Admin>  $admins
     * @param  array<string, mixed>  $payload
     */
    private function toAdmins(string $event, $admins, array $payload): void
    {
        $channels = $admins->map(fn (Admin $a) => $a->receivesBroadcastNotificationsOn())->values()->all();

        $this->dispatch($channels, $event, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toCustomer(SupportTicket $ticket, string $event, array $payload): void
    {
        $this->dispatch(['Modules.User.Models.User.'.$ticket->user_id], $event, $payload);
    }

    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(array $channels, string $event, array $payload): void
    {
        if ($channels === []) {
            return;
        }

        try {
            event(new SupportRealtimeEvent($channels, $event, $payload));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array<string, mixed> */
    private function forAdmin(SupportTicket $ticket): array
    {
        return (new AdminSupportTicketResource($ticket))->resolve();
    }

    /** @return array<string, mixed> */
    private function forCustomer(SupportTicket $ticket): array
    {
        return (new SupportTicketResource($ticket))->resolve();
    }

    /** @return array<string, mixed> */
    private function message(SupportMessage $message): array
    {
        return (new SupportMessageResource($message->loadMissing('admin')))->resolve();
    }

    private function customerName(SupportTicket $ticket): string
    {
        return (string) ($ticket->user?->name ?: $ticket->user?->phone ?: '#'.$ticket->user_id);
    }
}
