<?php

namespace Modules\WatchEarn\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\WatchEarn\Models\Ad;

/**
 * An ad in the viewer's player (WE-034): what to show, how long, and what the viewer earns for watching it and for
 * interacting. Nothing about the advertiser's money.
 *
 * @mixin Ad
 */
class FeedAdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = $this->country?->currency;

        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'body' => $this->body,
            'link_url' => $this->link_url,
            'media' => ['type' => $this->media_type, 'url' => $this->mediaUrl(), 'seconds' => $this->media_seconds],
            'advertiser' => $this->user ? [
                'name' => $this->user->name,
                'avatar' => method_exists($this->user, 'getFirstMediaUrl') ? ($this->user->getFirstMediaUrl('avatar') ?: null) : null,
            ] : null,
            'in_my_city' => $this->relationLoaded('cities') ? $this->cities->isNotEmpty() : null,
            'currency' => ['code' => $currency?->code, 'decimals' => (int) ($currency?->decimal_places ?? 2)],
            'earn' => ['view_minor' => $this->view_reward_minor, 'interaction_minor' => $this->interaction_reward_minor],
            'counts' => ['comments' => $this->comments_count, 'reactions' => $this->reactions_count],
        ];
    }
}
