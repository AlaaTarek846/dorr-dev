<?php

namespace Modules\Freelance\Services\Market;

use App\Models\Country;
use App\Services\General\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceService as Service;
use Modules\Freelance\Models\FreelanceServiceOrder as Order;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Moderation\Sanctions;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Plans\FreelancePerks;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Rights\Rights;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A freelancer's ready-made services (FL-L04) and the only door to their status:
 *
 *   draft / rejected → (submit) → pending_review → (staff) published | rejected       (no review in the country: straight to published)
 *   published ⇄ paused        an edit of a published or paused one goes back to review where review is on
 *   any but draft → suspended (staff) → paused (staff lifts it; the freelancer resumes)
 *
 * Only a verified freelancer, not limited by the staff, offers a service, and at most `services.max_active` are published or waiting at
 * once. The levels are basic (required), standard and premium; a higher one always costs more, and every price stays within the
 * country's project budget limits. A service with orders still going is paused, not deleted.
 */
class FreelancerServices
{
    public function __construct(
        private readonly FreelancePolicies $policies,
        private readonly FreelanceNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly FreelancePerks $perks,
    ) {}

    // -------------------------------------------------------------- the owner

    public function find(Freelancer $freelancer, string $uuid): Service
    {
        return $freelancer->services()->where('uuid', $uuid)->with(['tiers', 'addons', 'media', 'specialty.translations'])->firstOrFail();
    }

    /** @param  array<string, mixed>  $data */
    public function create(Freelancer $freelancer, array $data): Service
    {
        $country = Country::query()->with('currency')->findOrFail((int) $freelancer->country_id);

        return DB::transaction(function () use ($freelancer, $country, $data) {
            // FL-L13: a new service always carries terms (the defaults when nothing was chosen) where the switch is on.
            $service = Service::query()->create($this->fields($data + ['rights' => null], null, (int) $country->id) + [
                'freelancer_id' => $freelancer->id,
                'country_id' => $country->id,
                'currency_code' => (string) $country->currency?->code,
                'status' => Service::DRAFT,
            ]);
            $this->writeTiers($service, $data['tiers'] ?? []);
            $this->writeAddons($service, $data['addons'] ?? []);
            $this->assertMonthlyDays($service);

            return $service->load(['tiers', 'addons', 'media', 'specialty.translations']);
        });
    }

    /** @param  array<string, mixed>  $data  only what is sent changes; `tiers` / `addons` replace the whole list */
    public function update(Service $service, array $data): Service
    {
        return DB::transaction(function () use ($service, $data) {
            $s = $this->locked($service);
            if ($s->status === Service::SUSPENDED) {
                throw $this->state($s);
            }
            $s->fill($this->fields($data, $s, (int) $s->country_id));
            if (array_key_exists('tiers', $data)) {
                $this->writeTiers($s, $data['tiers'] ?? []);
            } else {
                // A new specialty can move the price limits: the levels must still fit.
                $this->assertPrices($s, $s->tiers()->get()->map(fn ($t) => ['level' => $t->level, 'price_minor' => $t->price_minor])->all());
            }
            if (array_key_exists('addons', $data)) {
                $this->writeAddons($s, $data['addons'] ?? []);
            }
            $this->assertMonthlyDays($s);
            // What clients see changed: it is looked at again before they see it.
            if (in_array($s->status, [Service::PUBLISHED, Service::PAUSED], true) && $this->reviewed($s)) {
                $s->fill(['status' => Service::PENDING, 'submitted_at' => now(), 'status_reason' => null]);
            }
            $s->save();

            return $s->load(['tiers', 'addons', 'media', 'specialty.translations']);
        });
    }

    /** Sent to the staff (or published at once where they don't look first). */
    public function submit(Service $service, Freelancer $freelancer): Service
    {
        return DB::transaction(function () use ($service, $freelancer) {
            $s = $this->locked($service);
            if (! in_array($s->status, [Service::DRAFT, Service::REJECTED], true)) {
                throw $this->state($s);
            }
            $this->assertCanOffer($s, $freelancer);
            if (! $s->tiers()->where('level', 'basic')->exists()) {
                throw new FreelanceException('service_tiers_basic', 422);
            }
            $reviewed = $this->reviewed($s);
            $s->fill(['status' => $reviewed ? Service::PENDING : Service::PUBLISHED, 'submitted_at' => now(), 'status_reason' => null]
                + ($reviewed ? [] : ['published_at' => $s->published_at ?? now()]))->save();

            return $s->load(['tiers', 'addons', 'media', 'specialty.translations']);
        });
    }

    public function pause(Service $service): Service
    {
        return $this->move($service, [Service::PUBLISHED], Service::PAUSED);
    }

    public function resume(Service $service, Freelancer $freelancer): Service
    {
        return DB::transaction(function () use ($service, $freelancer) {
            $s = $this->locked($service);
            if ($s->status !== Service::PAUSED) {
                throw $this->state($s);
            }
            $this->assertCanOffer($s, $freelancer);
            $s->fill(['status' => Service::PUBLISHED, 'published_at' => $s->published_at ?? now()])->save();

            return $s->load(['tiers', 'addons', 'media', 'specialty.translations']);
        });
    }

    /** Gone for good from the lists; the orders placed on it keep their copy. Not while orders are waiting or in progress. */
    public function delete(Service $service): void
    {
        DB::transaction(function () use ($service) {
            $s = $this->locked($service);
            $going = Order::query()->where('service_id', $s->id)
                ->whereHas('agreement', fn ($q) => $q->whereIn('status', [...Order::FILTERS['awaiting'], ...Order::FILTERS['active']]))->exists();
            if ($going) {
                throw new FreelanceException('service_has_orders', 409);
            }
            $s->clearMediaCollection('images');
            $s->delete();
        });
    }

    public function addImage(Service $service, UploadedFile $file): Media
    {
        $max = (int) config('freelance.services.max_images', 5);
        if ($service->getMedia('images')->count() >= $max) {
            throw new FreelanceException('service_images_limit', 422, ['max' => $max], ['max' => $max]);
        }

        return $service->addImage($file);
    }

    // -------------------------------------------------------------- the staff

    public function approve(Service $service, int $adminId): Service
    {
        $s = $this->decide($service, [Service::PENDING], Service::PUBLISHED, null, $adminId, 'freelance_service.approved');
        $this->notifier->notify($s->freelancer, 'service.approved', ['service' => (string) $s->title], ['service' => $s->uuid]);

        return $s;
    }

    public function reject(Service $service, string $reason, int $adminId): Service
    {
        $s = $this->decide($service, [Service::PENDING], Service::REJECTED, $reason, $adminId, 'freelance_service.rejected');
        $this->notifier->notify($s->freelancer, 'service.rejected', ['service' => (string) $s->title, 'reason' => $reason], ['service' => $s->uuid]);

        return $s;
    }

    /** Stopped at once wherever it stands; the orders already accepted carry on. */
    public function suspend(Service $service, string $reason, int $adminId): Service
    {
        $s = $this->decide($service, [Service::PENDING, Service::PUBLISHED, Service::PAUSED, Service::REJECTED], Service::SUSPENDED, $reason, $adminId, 'freelance_service.suspended');
        $this->notifier->notify($s->freelancer, 'service.suspended', ['service' => (string) $s->title, 'reason' => $reason], ['service' => $s->uuid]);

        return $s;
    }

    /** Back as paused: the freelancer turns it on again. */
    public function unsuspend(Service $service, int $adminId): Service
    {
        return $this->decide($service, [Service::SUSPENDED], Service::PAUSED, null, $adminId, 'freelance_service.unsuspended');
    }

    // -------------------------------------------------------------- what is shown

    /**
     * @param  'owner'|'client'|'staff'  $viewer  the owner downloads the images through their endpoint, the staff through theirs, a client
     *                                            gets links that expire
     * @return array<string, mixed>
     */
    public function present(Service $s, string $viewer): array
    {
        $s->loadMissing(['tiers', 'addons', 'media', 'specialty.translations', 'country.currency']);
        $tiers = $s->tiers->map(fn ($t) => [
            'level' => $t->level, 'level_label' => __('freelance.service_levels.'.$t->level), 'name' => $t->name, 'description' => $t->description,
            'price_minor' => $t->price_minor, 'delivery_days' => $t->delivery_days, 'revisions' => $t->revisions, 'features' => array_values((array) $t->features),
        ])->values();

        $out = [
            'uuid' => $s->uuid,
            'title' => $s->title,
            'description' => $s->description,
            'requirements' => $s->requirements,
            // FL-L13: who owns the work once it is paid and what the client may do with it (null: no terms).
            'rights' => Rights::summary(app(Rights::class)->effective($s->rights, (int) $s->country_id)),
            // FL-L05: once, or monthly (the prices are then per month).
            'billing' => $s->billing ?? Service::ONCE,
            'specialty' => $s->specialty === null ? null : ['id' => $s->specialty->id, 'name' => $s->specialty->translations->firstWhere('locale', app()->getLocale())?->name ?? $s->specialty->translations->first()?->name],
            'currency' => $s->currency_code,
            'decimals' => (int) ($s->country?->currency?->decimal_places ?? 2),
            'starting_price_minor' => $tiers->first()['price_minor'] ?? null,
            'tiers' => $tiers,
            'addons' => $s->addons->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'price_minor' => $a->price_minor, 'extra_days' => $a->extra_days])->values(),
            'images' => $this->images($s, $viewer),
            'published_at' => $s->published_at?->toIso8601String(),
        ];
        if ($viewer === 'client') {
            return $out;
        }

        $counts = collect(['awaiting', 'active', 'completed'])->mapWithKeys(fn ($k) => [$k => Order::query()->where('service_id', $s->id)->inStatus($k)->count()]);

        return $out + [
            'status' => $s->status,
            'status_label' => __('freelance.service_statuses.'.$s->status),
            'status_reason' => $s->status_reason,
            'orders' => $counts,
            'submitted_at' => $s->submitted_at?->toIso8601String(),
            'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
            'can' => [
                'edit' => $s->status !== Service::SUSPENDED,
                'submit' => in_array($s->status, [Service::DRAFT, Service::REJECTED], true),
                'pause' => $s->status === Service::PUBLISHED,
                'resume' => $s->status === Service::PAUSED,
                'delete' => $counts['awaiting'] === 0 && $counts['active'] === 0,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function images(Service $s, string $viewer): array
    {
        return $s->getMedia('images')->sortBy('order_column')->map(fn (Media $m) => [
            'id' => $m->id,
            'name' => $m->file_name,
            'size' => $m->size,
            'path' => match ($viewer) {
                'owner' => "services/{$s->uuid}/images/{$m->id}",
                'staff' => "freelance-services/{$s->uuid}/images/{$m->id}",
                default => null,
            },
            'url' => $viewer === 'client' ? // Signed on the path only, so the app can put it on the host it reaches (the server's own host may not resolve on a phone).
                URL::temporarySignedRoute('api.freelance.service.image', now()->addMinutes((int) config('freelance.services.image_link_minutes', 30)), ['media' => $m->id], absolute: false) : null,
        ])->values()->all();
    }

    // -------------------------------------------------------------- inside

    private function locked(Service $service): Service
    {
        return Service::query()->whereKey($service->id)->lockForUpdate()->with(['freelancer', 'country.currency'])->firstOrFail();
    }

    private function state(Service $s): FreelanceException
    {
        return new FreelanceException('service_invalid_state', 409, ['status' => __('freelance.service_statuses.'.$s->status)], ['status' => $s->status]);
    }

    private function reviewed(Service $s): bool
    {
        return (bool) ($this->policies->for((int) $s->country_id, $s->freelance_specialty_id)->get('services.require_review') ?? true);
    }

    /** Verified, not limited by the staff, and room for one more published or waiting service. */
    private function assertCanOffer(Service $s, Freelancer $freelancer): void
    {
        if ($freelancer->status !== FreelancerStatus::Verified) {
            throw new FreelanceException('service_unverified', 403);
        }
        app(Sanctions::class)->assertAllowed($freelancer, 'work');
        // The country's number, or the plan's when that is more (FL-L01 phase 4).
        $max = $this->perks->servicesLimit($freelancer, max(1, (int) ($this->policies->for((int) $s->country_id, $s->freelance_specialty_id)->get('services.max_active') ?? 5)));
        $live = Service::query()->where('freelancer_id', $freelancer->id)->whereKeyNot($s->id)->whereIn('status', [Service::PENDING, Service::PUBLISHED])->count();
        if ($live >= $max) {
            throw new FreelanceException('service_limit', 422, ['max' => $max], ['max' => $max]);
        }
    }

    /** @param  list<string>  $from */
    private function move(Service $service, array $from, string $to): Service
    {
        return DB::transaction(function () use ($service, $from, $to) {
            $s = $this->locked($service);
            if (! in_array($s->status, $from, true)) {
                throw $this->state($s);
            }
            $s->fill(['status' => $to])->save();

            return $s->load(['tiers', 'addons', 'media', 'specialty.translations']);
        });
    }

    /** @param  list<string>  $from */
    private function decide(Service $service, array $from, string $to, ?string $reason, int $adminId, string $action): Service
    {
        return DB::transaction(function () use ($service, $from, $to, $reason, $adminId, $action) {
            $s = $this->locked($service);
            if (! in_array($s->status, $from, true)) {
                throw $this->state($s);
            }
            $before = $s->status;
            $s->fill(['status' => $to, 'status_reason' => $reason === null ? null : mb_substr($reason, 0, 500), 'reviewed_at' => now(), 'reviewed_by' => $adminId]
                + ($to === Service::PUBLISHED ? ['published_at' => $s->published_at ?? now()] : []))->save();
            $this->audit->record($action, $s, ['status' => $before], ['status' => $to, 'reason' => $reason]);

            return $s->load(['tiers', 'addons', 'media', 'specialty.translations']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data, ?Service $current = null, ?int $countryId = null): array
    {
        $out = [];
        if (array_key_exists('rights', $data)) {
            $out['rights'] = app(Rights::class)->forListing((int) ($countryId ?? $current?->country_id), is_array($data['rights']) ? $data['rights'] : null);
        }
        foreach (['title', 'description', 'requirements'] as $key) {
            if (array_key_exists($key, $data)) {
                $text = $data[$key] === null ? null : trim(strip_tags((string) $data[$key]));
                $out[$key] = $text === '' ? null : $text;
            }
        }
        if (array_key_exists('billing', $data)) {
            $out['billing'] = in_array($data['billing'], Service::BILLINGS, true) ? $data['billing'] : Service::ONCE;
        }
        if (array_key_exists('specialty_id', $data)) {
            $id = $data['specialty_id'] === null ? null : (int) $data['specialty_id'];
            if ($id !== null && ! FreelanceSpecialty::query()->whereKey($id)->where('status', true)->exists()) {
                throw new FreelanceException('specialty_not_found', 422);
            }
            $out['freelance_specialty_id'] = $id;
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $items  the whole list */
    private function writeTiers(Service $s, array $items): void
    {
        $levels = array_column($items, 'level');
        if (! in_array('basic', $levels, true)) {
            throw new FreelanceException('service_tiers_basic', 422);
        }
        usort($items, fn ($a, $b) => array_search($a['level'], Service::LEVELS, true) <=> array_search($b['level'], Service::LEVELS, true));
        $last = null;
        foreach ($items as $i) {
            if ($last !== null && (int) $i['price_minor'] <= $last) {
                throw new FreelanceException('service_tiers_order', 422);
            }
            $last = (int) $i['price_minor'];
        }
        $this->assertPrices($s, $items);

        $s->tiers()->delete();
        foreach ($items as $i) {
            $s->tiers()->create([
                'level' => $i['level'],
                'name' => trim(strip_tags((string) ($i['name'] ?? __('freelance.service_levels.'.$i['level'])))),
                'description' => isset($i['description']) ? (trim(strip_tags((string) $i['description'])) ?: null) : null,
                'price_minor' => (int) $i['price_minor'],
                'delivery_days' => (int) $i['delivery_days'],
                'revisions' => (int) ($i['revisions'] ?? 0),
                'features' => array_values(array_filter(array_map(fn ($f) => trim(strip_tags((string) $f)), (array) ($i['features'] ?? [])), fn ($f) => $f !== '')),
            ]);
        }
    }

    /** FL-L05: a month's work is delivered within the month — no level (with its extras) may take longer. */
    private function assertMonthlyDays(Service $s): void
    {
        if (! $s->monthly()) {
            return;
        }
        $period = ServiceSubscriptions::periodDays();
        $extra = (int) $s->addons()->max('extra_days');
        if ((int) $s->tiers()->max('delivery_days') + $extra > $period) {
            throw new FreelanceException('service_monthly_days', 422, ['days' => $period], ['days' => $period]);
        }
    }

    /** @param  list<array<string, mixed>>  $items */
    private function writeAddons(Service $s, array $items): void
    {
        $s->addons()->delete();
        foreach (array_values($items) as $position => $i) {
            $s->addons()->create([
                'title' => trim(strip_tags((string) $i['title'])),
                'price_minor' => (int) $i['price_minor'],
                'extra_days' => (int) ($i['extra_days'] ?? 0),
                'sort_order' => $position + 1,
            ]);
        }
    }

    /**
     * Every level's price within the country's (and the specialty's) project budget limits, since an order becomes a project.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function assertPrices(Service $s, array $items): void
    {
        $policy = $this->policies->for((int) $s->country_id, $s->freelance_specialty_id);
        $min = $policy->get('project.min_budget_minor');
        $max = $policy->get('project.max_budget_minor');
        foreach ($items as $i) {
            if ($min !== null && (int) $i['price_minor'] < (int) $min) {
                throw new FreelanceException('service_below_min', 422, ['min' => $this->money((int) $min, $s)], ['min_minor' => (int) $min]);
            }
            if ($max !== null && (int) $i['price_minor'] > (int) $max) {
                throw new FreelanceException('service_above_max', 422, ['max' => $this->money((int) $max, $s)], ['max_minor' => (int) $max]);
            }
        }
    }

    private function money(int $minor, Service $s): string
    {
        $decimals = (int) (Country::query()->with('currency')->find($s->country_id)?->currency?->decimal_places ?? 2);

        return number_format($minor / (10 ** $decimals), $decimals).' '.$s->currency_code;
    }
}
