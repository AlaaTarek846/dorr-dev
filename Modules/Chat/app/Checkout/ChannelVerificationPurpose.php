<?php

namespace Modules\Chat\Checkout;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Enums\ConversationType;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatPackage;
use Modules\Chat\Services\ChatPackageService;
use Modules\Wallet\Contracts\CheckoutPurpose;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Support\Payments\CheckoutLine;

/**
 * The ✔ next to a channel's name, for one package's period (like WhatsApp's Meta Verified: a
 * running subscription keeps it) — `{channel_id, package_id}`. Only the channel's owner buys it.
 */
class ChannelVerificationPurpose implements CheckoutPurpose
{
    public const KEY = 'chat_channel_verification';

    public function __construct(private readonly ChatPackageService $packages) {}

    public function line(Model $owner, Country $country, array $reference): CheckoutLine
    {
        $channel = ChatConversation::query()->where('type', ConversationType::Channel->value)
            ->where('uuid', (string) ($reference['channel_id'] ?? ''))->with('group')->first();

        $isOwner = $channel?->activeParticipants()->of($owner)->where('role', ParticipantRole::Owner->value)->exists();

        if ($channel === null || $channel->group === null || ! $isOwner) {
            throw new ChatException('channel_not_found', 404);
        }

        $package = $this->packages->sellable(ChatPackage::KIND_CHANNEL_VERIFICATION, (int) ($reference['package_id'] ?? 0), $country);

        return new CheckoutLine(
            amountMinor: (int) $package->priceIn($country->id),
            title: __('chat.checkout.verification_title', ['name' => $channel->group->name]),
            subtitle: $package->translatedName(),
            reference: ['channel_id' => $channel->uuid, 'package_id' => $package->id],
        );
    }

    public function fulfil(Checkout $checkout): void
    {
        $channel = ChatConversation::query()->where('uuid', $checkout->referenceValue('channel_id'))->with('group')->firstOrFail();
        $package = ChatPackage::query()->findOrFail($checkout->referenceValue('package_id'));

        $this->packages->grant($checkout, $package, $channel->group);
    }
}
