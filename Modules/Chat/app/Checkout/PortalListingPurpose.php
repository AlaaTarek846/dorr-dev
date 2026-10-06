<?php

namespace Modules\Chat\Checkout;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatPackage;
use Modules\Chat\Models\ChatPortal;
use Modules\Chat\Services\ChatPackageService;
use Modules\Wallet\Contracts\CheckoutPurpose;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Support\Payments\CheckoutLine;

/**
 * "Show my portal on the portals page" for one package's period — `{portal_id, package_id}`.
 */
class PortalListingPurpose implements CheckoutPurpose
{
    public const KEY = 'chat_portal_listing';

    public function __construct(private readonly ChatPackageService $packages) {}

    public function line(Model $owner, Country $country, array $reference): CheckoutLine
    {
        $portal = ChatPortal::query()->with('translations')->where('uuid', (string) ($reference['portal_id'] ?? ''))->first();

        if ($portal === null || ! $portal->isOwnedBy($owner)) {
            throw new ChatException('portal_not_found', 404);
        }

        if (! $portal->status) {
            throw new ChatException('portal_disabled', 422);
        }

        $package = $this->packages->sellable(ChatPackage::KIND_PORTAL, (int) ($reference['package_id'] ?? 0), $country);

        return new CheckoutLine(
            amountMinor: (int) $package->priceIn($country->id),
            title: __('chat.checkout.portal_title', ['name' => $portal->translatedName() ?? '']),
            subtitle: $package->translatedName(),
            reference: ['portal_id' => $portal->uuid, 'package_id' => $package->id],
        );
    }

    public function fulfil(Checkout $checkout): void
    {
        $portal = ChatPortal::query()->where('uuid', $checkout->referenceValue('portal_id'))->firstOrFail();
        $package = ChatPackage::query()->findOrFail($checkout->referenceValue('package_id'));

        $this->packages->grant($checkout, $package, $portal);
    }
}
