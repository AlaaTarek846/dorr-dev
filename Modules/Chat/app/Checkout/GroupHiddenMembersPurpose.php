<?php

namespace Modules\Chat\Checkout;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Services\GroupService;
use Modules\Wallet\Contracts\CheckoutPurpose;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Support\Payments\CheckoutLine;

/**
 * Hidden members for one group, paid once — `{group_id}`. Bought by one of its admins, right after
 * creating the group or later from its settings; a failed payment leaves an ordinary group.
 * Priced per country on the chat settings screen (chat_settings.hidden_members_prices).
 */
class GroupHiddenMembersPurpose implements CheckoutPurpose
{
    public const KEY = 'chat_group_hidden_members';

    public function __construct(private readonly GroupService $groups) {}

    public function line(Model $owner, Country $country, array $reference): CheckoutLine
    {
        $conversation = ChatConversation::query()->where('uuid', (string) ($reference['group_id'] ?? ''))->with('group')->first();

        if ($conversation === null || $conversation->group === null) {
            throw ChatException::groupOnly();
        }

        $group = $this->groups->assertCanHideMembers($owner, $conversation);
        $amount = ChatSetting::current()->hiddenMembersPriceIn($country->id) ?? throw new ChatException('hidden_members_unavailable', 422);

        return new CheckoutLine(
            amountMinor: $amount,
            title: __('chat.checkout.hidden_members_title', ['name' => $group->name]),
            subtitle: __('chat.checkout.hidden_members_subtitle'),
            reference: ['group_id' => $conversation->uuid],
        );
    }

    public function fulfil(Checkout $checkout): void
    {
        $conversation = ChatConversation::query()->where('uuid', $checkout->referenceValue('group_id'))->with('group')->firstOrFail();

        $this->groups->hideMembers($checkout->owner(), $conversation);
    }
}
