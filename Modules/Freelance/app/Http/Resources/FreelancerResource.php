<?php

namespace Modules\Freelance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Account\FreelancerAvailabilityService;
use Modules\Freelance\Services\Account\FreelancerCompleteness;
use Modules\Freelance\Services\Account\FreelancerProfileService;
use Modules\Freelance\Services\Verification\FreelancerGate;

/**
 * The freelancer as their own app sees them. `missing` lists what the profile still needs
 * (`name`, `terms`); `terms_version` is the version to accept.
 *
 * @mixin Freelancer
 */
class FreelancerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'phone' => $this->phone,
            'headline' => $this->headline,
            'bio' => $this->bio,
            'years_of_experience' => $this->years_of_experience,
            'contact_languages' => $this->contact_languages ?? [],
            'city' => $this->city,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'gender' => $this->gender?->value,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'country_id' => $this->country_id,
            'locale' => $this->locale,
            'status' => $this->status?->value,
            'status_reason' => $this->status_reason,
            'residency' => $this->residency,
            'verified_at' => $this->verified_at?->toIso8601String(),
            // The verification gate (FL-110): what every app shows — can they apply, and if not, the next step.
            ...app(FreelancerGate::class)->summary($this->resource),
            // Whether new work can be given to them now (a pause or time off says no) — see FL-106.
            'availability' => ['can_receive_offers' => app(FreelancerAvailabilityService::class)->canReceiveOffers($this->resource), 'accepting_requests' => ! app(FreelancerAvailabilityService::class)->isPaused($this->resource), 'on_time_off' => app(FreelancerAvailabilityService::class)->onTimeOff($this->resource) !== null],
            'avatar_url' => $this->getFirstMediaUrl('avatar') ?: null,
            'terms' => [
                'accepted' => $this->resource->hasAcceptedTerms(),
                'accepted_at' => $this->terms_accepted_at?->toIso8601String(),
                'current_version' => (string) config('freelance.terms_version'),
            ],
            'missing' => app(FreelancerProfileService::class)->missing($this->resource),
            // How much of the profile is filled in, and what to do next (it only measures; the status moves on its own steps).
            'completeness' => app(FreelancerCompleteness::class)->of($this->resource),
        ];
    }
}
