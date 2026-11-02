<?php

namespace Modules\WatchEarn\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\WatchEarn\Models\Ad;

/**
 * An ad as its advertiser sees it (and the admin, with `admin` on the request): status, content, audience, dates,
 * budget by bucket. Never the price's split between the platform and the viewers (REQ-024) — except to the admin.
 *
 * @mixin Ad
 */
class AdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = (bool) $request->attributes->get('we_admin');
        $currency = $this->country?->currency;

        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'scheduled' => $this->status->value === 'active' && $this->starts_at->isFuture(),
            'title' => $this->title,
            'body' => $this->body,
            'link_url' => $this->link_url,
            'media' => [
                'type' => $this->media_type,
                'url' => $this->mediaUrl(),
                'seconds' => $this->media_seconds,
                'size' => $this->media_size,
                'version' => $this->media_version,
            ],
            'audience' => [
                'country_id' => $this->country_id,
                'cities' => $this->whenLoaded('cities', fn () => $this->cities->pluck('city_id')->all()),
                'gender' => $this->target_gender,
                'locale' => $this->target_locale,
            ],
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'currency' => ['code' => $currency?->code, 'decimals' => (int) ($currency?->decimal_places ?? 2)],
            'budget' => [
                'total_minor' => $this->budget_minor,
                'spent_minor' => $this->spent_minor,
                'remaining_minor' => $this->reservedMinor(),
                'price_per_view_minor' => $this->price_per_view_minor,
                'views_left' => $this->viewsLeft(),
                'remaining_by_bucket' => [
                    'reward_minor' => $this->reserved_reward_minor,
                    'spend_only_minor' => $this->reserved_spend_only_minor,
                    'withdrawable_minor' => $this->reserved_withdrawable_minor,
                ],
            ],
            'split' => $this->when($admin, fn () => [
                'platform_share_minor' => $this->platform_share_minor,
                'view_reward_minor' => $this->view_reward_minor,
                'interaction_reward_minor' => $this->interaction_reward_minor,
                'unclaimed_interaction' => $this->unclaimed_interaction,
            ]),
            'counts' => [
                'impressions' => $this->impressions_count,
                'completed' => $this->completed_count,
                'interactions' => $this->interactions_count,
                'comments' => $this->comments_count,
                'reactions' => $this->reactions_count,
                'reports' => $this->reports_count,
            ],
            'reject_reason' => $this->reject_reason,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'suspended_at' => $this->suspended_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'advertiser' => $this->when($admin, fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'phone' => $this->user->phone] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
