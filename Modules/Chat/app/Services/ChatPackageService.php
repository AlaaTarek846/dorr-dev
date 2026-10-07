<?php

namespace Modules\Chat\Services;

use App\Models\Country;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Models\ChatPackage;
use Modules\Chat\Models\ChatPortal;
use Modules\Chat\Models\ChatSubscription;
use Modules\Chat\Support\ParticipantType;
use Modules\Wallet\Models\Checkout;

/**
 * The packages a portal listing / a channel verification is sold in, and what paying for one
 * does: a period added to the portal's `listed_until` or the channel's `verified_until` — after
 * the one still running, when there is one, so a renewal never loses days.
 */
class ChatPackageService
{
    /**
     * Active packages of a kind that have a price in this country (in its currency).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forCountry(string $kind, Country $country): Collection
    {
        return ChatPackage::query()->active()->where('kind', $kind)
            ->whereHas('prices', fn ($q) => $q->where('country_id', $country->id))
            ->with(['translations', 'prices'])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (ChatPackage $package) => $this->present($package, $country));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ChatPackage $package, Country $country): array
    {
        return [
            'id' => $package->id,
            'kind' => $package->kind,
            'name' => $package->translatedName(),
            'description' => $package->translated('description'),
            'period' => $package->period,
            'period_count' => $package->period_count,
            'amount_minor' => $package->priceIn($country->id),
            'currency_code' => $country->currency?->code,
        ];
    }

    /**
     * An active package of this kind, priced in this country.
     */
    public function sellable(string $kind, int $packageId, Country $country): ChatPackage
    {
        $package = ChatPackage::query()->active()->where('kind', $kind)->with(['translations', 'prices'])->find($packageId);

        if ($package === null || $package->priceIn($country->id) === null) {
            throw new ChatException('package_unavailable', 422);
        }

        return $package;
    }

    /**
     * Paid: one more period for the portal / channel, recorded as a subscription.
     */
    public function grant(Checkout $checkout, ChatPackage $package, Model $subject): ChatSubscription
    {
        return DB::transaction(function () use ($checkout, $package, $subject) {
            [$subjectType, $column] = $subject instanceof ChatPortal ? ['portal', 'listed_until'] : ['channel', 'verified_until'];

            /** @var ChatPortal|ChatGroup $locked */
            $locked = $subject::query()->lockForUpdate()->findOrFail($subject->getKey());
            /** @var CarbonInterface|null $current */
            $current = $locked->{$column};
            $start = $current !== null && $current->isFuture() ? $current : now();
            $end = $package->endFrom($start);

            $locked->update([$column => $end]);

            return ChatSubscription::query()->create([
                'kind' => $package->kind,
                'subject_type' => $subjectType,
                'subject_id' => $locked->getKey(),
                'chat_package_id' => $package->id,
                'checkout_id' => $checkout->id,
                'owner_type' => $checkout->owner_type,
                'owner_id' => $checkout->owner_id,
                'amount_minor' => $checkout->amount_minor,
                'currency_id' => $checkout->currency_id,
                'starts_at' => $start,
                'ends_at' => $end,
            ]);
        });
    }

    /**
     * The owner's past and running periods for a portal / channel, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function history(string $subjectType, int $subjectId): Collection
    {
        return ChatSubscription::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->with('package.translations')->latest('id')->limit(50)->get()
            ->map(fn (ChatSubscription $s) => [
                'package' => $s->package?->translatedName(),
                'amount_minor' => $s->amount_minor,
                'starts_at' => $s->starts_at?->toIso8601String(),
                'ends_at' => $s->ends_at?->toIso8601String(),
                'is_running' => $s->starts_at?->isPast() && $s->ends_at?->isFuture(),
            ]);
    }

    public static function ownerAlias(Model $owner): string
    {
        return ParticipantType::aliasFor($owner);
    }
}
