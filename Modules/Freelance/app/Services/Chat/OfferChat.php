<?php

namespace Modules\Freelance\Services\Chat;

use Modules\Freelance\Enums\FreelanceOfferStatus;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\User\Models\User;

/**
 * The talk about an offer before the agreement (FL-308), on the chat of FL-006: one room per project and freelancer, opened by
 * the client from an offer that is still alive (the freelancer answers in it). Phone numbers, emails and links are kept out of it
 * until the agreement. When the offer ends (turned down, taken back, another one chosen) or the project is cancelled the room stays
 * for both to read but nobody writes in it any more.
 */
class OfferChat
{
    public const CONTEXT = 'project';

    public function __construct(private readonly FreelanceChatService $chat) {}

    /** @throws FreelanceException freelance_chat_not_available (409) — the offer is over, or the project is not taking part any more */
    public function open(Offer $offer, User $client): FreelanceConversation
    {
        $project = $offer->project;
        $alive = in_array($offer->status, [FreelanceOfferStatus::Submitted, FreelanceOfferStatus::Viewed, FreelanceOfferStatus::Shortlisted, FreelanceOfferStatus::Accepted], true);
        if (! $alive || ! in_array($project->status, [FreelanceProjectStatus::Published, FreelanceProjectStatus::Awarded], true) || (int) $project->client_id !== (int) $client->id) {
            throw new FreelanceException('chat_not_available', 409);
        }

        return $this->chat->open($client, $offer->freelancer, self::CONTEXT, $project->id);
    }

    /** The room about this offer, if it was opened. */
    public function roomFor(Offer $offer): ?FreelanceConversation
    {
        return FreelanceConversation::query()->where('context_type', self::CONTEXT)->where('context_id', $offer->project_id)->where('freelancer_id', $offer->freelancer_id)->first();
    }

    /** @return array{uuid: string, unread: int, can_write: bool}|null what the offer screens show of the room */
    public function summary(Offer $offer, string $side): ?array
    {
        $room = $this->roomFor($offer);

        return $room === null ? null : ['uuid' => $room->uuid, 'unread' => (int) $room->{$side === 'client' ? 'client_unread' : 'freelancer_unread'}, 'can_write' => $this->chat->canWrite($room)];
    }

    /** Nobody writes in the room of this freelancer on this project any more (null freelancer: every room of the project). */
    public function freeze(Project $project, ?int $freelancerId = null): void
    {
        FreelanceConversation::query()->where('context_type', self::CONTEXT)->where('context_id', $project->id)->where('status', FreelanceConversation::OPEN)
            ->when($freelancerId !== null, fn ($q) => $q->where('freelancer_id', $freelancerId))
            ->update(['status' => FreelanceConversation::READ_ONLY]);
    }
}
