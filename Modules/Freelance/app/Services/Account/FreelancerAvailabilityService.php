<?php

namespace Modules\Freelance\Services\Account;

use Carbon\CarbonImmutable;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerAvailability;
use Modules\Freelance\Models\FreelancerTimeOff;

/**
 * FL-106: whether a freelancer can be given new work — paused, or on time off, or open — plus their guide price and weekly
 * hours. A pause with an end day lifts itself after that day (nothing runs on a timer: it is worked out when asked).
 * `canReceiveOffers()` is the one rule the offers (FL-301) and invitations check.
 */
class FreelancerAvailabilityService
{
    public function row(Freelancer $freelancer): FreelancerAvailability
    {
        return $freelancer->availability ?? new FreelancerAvailability(['freelancer_id' => $freelancer->id, 'accepting_requests' => true, 'rate_type' => 'none']);
    }

    /** @param  array<string, mixed>  $data  already validated */
    public function update(Freelancer $freelancer, array $data): FreelancerAvailability
    {
        $row = $freelancer->availability ?? new FreelancerAvailability(['freelancer_id' => $freelancer->id]);

        if (array_key_exists('accepting_requests', $data)) {
            $row->accepting_requests = (bool) $data['accepting_requests'];
            // Open again: the pause details go away with it.
            if ($row->accepting_requests) {
                $row->paused_until = null;
                $row->pause_reason = null;
            } else {
                $row->paused_until = $data['paused_until'] ?? null;
                $row->pause_reason = $this->clean($data['pause_reason'] ?? null);
            }
        }

        if (array_key_exists('rate_type', $data)) {
            $none = $data['rate_type'] === 'none';
            $row->rate_type = $data['rate_type'];
            $row->rate_min_minor = $none ? null : ($data['rate_min_minor'] ?? null);
            $row->rate_max_minor = $none ? null : ($data['rate_max_minor'] ?? null);
        }

        if (array_key_exists('weekly_hours', $data)) {
            $row->weekly_hours = collect($data['weekly_hours'] ?? [])->map(fn ($d) => ['day' => (int) $d['day'], 'from' => $d['from'], 'to' => $d['to']])->sortBy('day')->values()->all();
        }

        $row->save();
        $freelancer->setRelation('availability', $row);

        return $row;
    }

    /** @return \Illuminate\Support\Collection<int, FreelancerTimeOff> the periods not over yet, soonest first */
    public function timeOff(Freelancer $freelancer)
    {
        return $freelancer->timeOff()->whereDate('ends_on', '>=', $this->today())->orderBy('starts_on')->get();
    }

    /** @param  array{starts_on: string, ends_on: string, reason?: ?string}  $data */
    public function addTimeOff(Freelancer $freelancer, array $data): FreelancerTimeOff
    {
        $max = (int) config('freelance.profile.max_time_off', 12);
        if ($this->timeOff($freelancer)->count() >= $max) {
            throw new FreelanceException('profile_limit', 422, ['max' => $max], ['max' => $max]);
        }
        $clash = $freelancer->timeOff()->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->exists();
        if ($clash) {
            throw new FreelanceException('time_off_overlap');
        }

        return $freelancer->timeOff()->create(['starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'reason' => $this->clean($data['reason'] ?? null)]);
    }

    public function removeTimeOff(FreelancerTimeOff $period): void
    {
        $period->delete();
    }

    /** Is the pause still on today? (A pause with an end day is over after that day.) */
    public function isPaused(Freelancer $freelancer): bool
    {
        $row = $freelancer->availability;
        if ($row === null || $row->accepting_requests) {
            return false;
        }

        return $row->paused_until === null || $row->paused_until->toDateString() >= $this->today();
    }

    public function onTimeOff(Freelancer $freelancer): ?FreelancerTimeOff
    {
        $today = $this->today();

        return $freelancer->timeOff()->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)->first();
    }

    public function canReceiveOffers(Freelancer $freelancer): bool
    {
        return ! $this->isPaused($freelancer) && $this->onTimeOff($freelancer) === null;
    }

    /** @throws FreelanceException freelance_unavailable (422) */
    public function assertCanReceiveOffers(Freelancer $freelancer): void
    {
        if ($this->isPaused($freelancer)) {
            throw new FreelanceException('unavailable', 422, ['reason' => __('freelance.unavailable.paused')], ['reason' => 'paused']);
        }
        if ($this->onTimeOff($freelancer) !== null) {
            throw new FreelanceException('unavailable', 422, ['reason' => __('freelance.unavailable.time_off')], ['reason' => 'time_off']);
        }
    }

    /** @return array<string, mixed> what the app shows */
    public function present(Freelancer $freelancer): array
    {
        $row = $this->row($freelancer);
        $off = $this->onTimeOff($freelancer);

        return [
            'accepting_requests' => ! $this->isPaused($freelancer),
            'paused_until' => $this->isPaused($freelancer) ? $row->paused_until?->toDateString() : null,
            'pause_reason' => $this->isPaused($freelancer) ? $row->pause_reason : null,
            'can_receive_offers' => $this->canReceiveOffers($freelancer),
            'on_time_off' => $off !== null,
            'rate' => ['type' => $row->rate_type ?? 'none', 'min_minor' => $row->rate_min_minor, 'max_minor' => $row->rate_max_minor],
            'weekly_hours' => $row->weekly_hours ?? [],
            'time_off' => $this->timeOff($freelancer)->map(fn (FreelancerTimeOff $t) => $this->presentTimeOff($t))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentTimeOff(FreelancerTimeOff $t): array
    {
        return ['id' => $t->id, 'starts_on' => $t->starts_on->toDateString(), 'ends_on' => $t->ends_on->toDateString(), 'reason' => $t->reason];
    }

    private function today(): string
    {
        return CarbonImmutable::now()->toDateString();
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim(strip_tags($text));

        return $text === '' ? null : $text;
    }
}
