<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Chat\Enums\PrivacyAudience;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatBlock;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Chat\Support\ParticipantType;

/**
 * Every "may A do this to B?" rule of the chat, in one place — blocks, who can message me,
 * who can add me to groups, who can call me. (In LeeTaxi these settings were stored but never
 * checked; here nothing reaches another person without passing through this class.)
 */
class ChatPrivacy
{
    public function settingsFor(Model $owner): ChatPrivacySetting
    {
        return ChatPrivacySetting::query()->firstOrCreate(
            ['owner_type' => ParticipantType::aliasFor($owner), 'owner_id' => $owner->getKey()],
        );
    }

    /**
     * Read-only view of someone's settings — defaults when they never changed anything
     * (nothing is written just because somebody else looked).
     */
    public function peek(Model $owner): ChatPrivacySetting
    {
        return ChatPrivacySetting::query()
            ->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey())
            ->first() ?? new ChatPrivacySetting([
                'last_seen' => PrivacyAudience::Everyone,
                'profile_photo' => PrivacyAudience::Everyone,
                'read_receipts' => true,
                'who_can_message' => PrivacyAudience::Everyone,
                'who_can_add_to_groups' => PrivacyAudience::Everyone,
                'who_can_call' => PrivacyAudience::Everyone,
                'block_screenshots' => false,
                'notification_preview' => true,
            ]);
    }

    /**
     * Blocked in either direction — neither side can reach the other.
     */
    public function isBlockedBetween(Model $a, Model $b): bool
    {
        $aType = ParticipantType::aliasFor($a);
        $bType = ParticipantType::aliasFor($b);

        return ChatBlock::query()
            ->where(fn ($q) => $q->where(['blocker_type' => $aType, 'blocker_id' => $a->getKey(), 'blocked_type' => $bType, 'blocked_id' => $b->getKey()]))
            ->orWhere(fn ($q) => $q->where(['blocker_type' => $bType, 'blocker_id' => $b->getKey(), 'blocked_type' => $aType, 'blocked_id' => $a->getKey()]))
            ->exists();
    }

    public function hasBlocked(Model $blocker, Model $blocked): bool
    {
        return ChatBlock::query()->where([
            'blocker_type' => ParticipantType::aliasFor($blocker), 'blocker_id' => $blocker->getKey(),
            'blocked_type' => ParticipantType::aliasFor($blocked), 'blocked_id' => $blocked->getKey(),
        ])->exists();
    }

    /**
     * Did `$owner` save `$other` in their contacts?
     */
    public function hasInContacts(Model $owner, Model $other): bool
    {
        return ChatContact::query()->ownedBy($owner)
            ->where('contact_type', ParticipantType::aliasFor($other))->where('contact_id', $other->getKey())
            ->exists();
    }

    /**
     * May `$sender` start a direct chat with `$recipient`? Returns whether it has to go through
     * message requests (the recipient never saved the sender).
     *
     * @throws ChatException
     */
    public function assertCanMessage(Model $sender, Model $recipient): bool
    {
        $this->assertReachable($sender, $recipient);

        $isContact = $this->hasInContacts($recipient, $sender);

        return match ($this->peek($recipient)->who_can_message) {
            PrivacyAudience::Everyone => ! $isContact,
            PrivacyAudience::Contacts => $isContact ? false : throw ChatException::notAllowedToMessage(),
            PrivacyAudience::Nobody => throw ChatException::notAllowedToMessage(),
        };
    }

    public function canAddToGroup(Model $adder, Model $member): bool
    {
        if ($this->isBlockedBetween($adder, $member)) {
            return false;
        }

        return $this->audienceAllows($this->peek($member)->who_can_add_to_groups, $member, $adder);
    }

    /**
     * @throws ChatException
     */
    public function assertCanCall(Model $caller, Model $callee): void
    {
        $this->assertReachable($caller, $callee);

        if (! $this->audienceAllows($this->peek($callee)->who_can_call, $callee, $caller)) {
            throw ChatException::notAllowedToCall();
        }
    }

    /**
     * @throws ChatException
     */
    public function assertReachable(Model $from, Model $to): void
    {
        if (ParticipantType::key($from) === ParticipantType::key($to)) {
            throw ChatException::toSelf();
        }

        if (! ParticipantType::isEnabled(ParticipantType::aliasFor($to))) {
            throw ChatException::participantDisabled();
        }

        if ($this->isBlockedBetween($from, $to)) {
            throw ChatException::blocked();
        }
    }

    public function audienceAllows(PrivacyAudience $audience, Model $owner, Model $viewer): bool
    {
        return match ($audience) {
            PrivacyAudience::Everyone => true,
            PrivacyAudience::Nobody => false,
            PrivacyAudience::Contacts => $this->hasInContacts($owner, $viewer),
        };
    }

    /**
     * The token behind my chat QR code (created the first time it's asked for).
     */
    public function qrToken(Model $owner, bool $reset = false): string
    {
        $settings = $this->settingsFor($owner);

        if ($reset || $settings->qr_token === null) {
            $settings->update(['qr_token' => Str::random(32)]);
        }

        return $settings->qr_token;
    }
}
