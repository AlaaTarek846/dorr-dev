<?php

namespace Modules\Provider\Services\Establishments;

use App\Enums\ServiceAudience;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Provider\Enums\DocumentSubject;
use Modules\Provider\Enums\EstablishmentStatus;
use Modules\Provider\Enums\EstablishmentType;
use Modules\Provider\Enums\VehicleStatus;
use Modules\Provider\Exceptions\ProviderException;
use Modules\Provider\Models\Branch;
use Modules\Provider\Models\Establishment;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Provider;
use Modules\Provider\Services\Documents\DocumentRequirements;

/**
 * Registering and running an establishment (A-03, A-04): after signing in, the person gives their basic data and the
 * service they offer; the establishment is made with its main branch and the owner's seat holding every permission.
 * An individual and a company are the same thing here, a company just adds branches and people. Moving from
 * individual to company keeps the same establishment, its ratings and its wallet, and goes back to review.
 */
class EstablishmentService
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly EstablishmentAccess $access,
        private readonly DocumentRequirements $requirements,
    ) {}

    /** Services a provider can register in: active leaf categories meant for providers, in the admin's order. */
    public function offerableServices(): Builder
    {
        return ServiceCategory::query()
            ->where('status', true)
            ->whereJsonContains('audiences', ServiceAudience::Provider->value)
            ->whereDoesntHave('children')
            ->orderBy('sort_order');
    }

    /**
     * @param  array{type: string, name: string, country_id: int, commercial_register_no?: string|null, tax_number?: string|null, service_ids: list<int>, branch: array{name?: string|null, lat: float, lng: float, radius_km?: float|null, address?: string|null}}  $data
     */
    public function create(Provider $owner, array $data): Membership
    {
        // One person owns one establishment; growing into a company changes its type instead (§14).
        $owns = $owner->memberships()->where('is_owner', true)->whereHas('establishment')->exists();
        if ($owns) {
            throw new ProviderException('already_owns');
        }
        $serviceIds = $this->validServices($data['service_ids']);

        return DB::transaction(function () use ($owner, $data, $serviceIds): Membership {
            $establishment = Establishment::query()->create([
                'owner_id' => $owner->id,
                'type' => $data['type'],
                'name' => $data['name'],
                'country_id' => $data['country_id'],
                'commercial_register_no' => $data['commercial_register_no'] ?? null,
                'tax_number' => $data['tax_number'] ?? null,
                'status' => EstablishmentStatus::Draft,
            ]);
            $establishment->services()->sync(array_fill_keys($serviceIds, ['status' => true]));
            $establishment->branches()->create([
                'name' => $data['branch']['name'] ?? $data['name'],
                'is_main' => true,
                'lat' => $data['branch']['lat'],
                'lng' => $data['branch']['lng'],
                'radius_km' => $data['branch']['radius_km'] ?? config('provider.default_radius_km', 100),
                'address' => $data['branch']['address'] ?? null,
            ]);

            return $this->memberships->createOwner($establishment, $owner);
        });
    }

    /**
     * @param  array{type?: string, name?: string, commercial_register_no?: string|null, tax_number?: string|null, service_ids?: list<int>}  $data
     */
    public function update(Membership $actor, array $data): Establishment
    {
        $this->assertOwner($actor);
        $establishment = $actor->establishment;

        return DB::transaction(function () use ($establishment, $data): Establishment {
            if (isset($data['service_ids'])) {
                $establishment->services()->sync(array_fill_keys($this->validServices($data['service_ids']), ['status' => true]));
            }
            $establishment->fill(array_intersect_key($data, array_flip(['type', 'name', 'commercial_register_no', 'tax_number'])));
            // A new type or new papers are looked at again before the next request reaches them.
            $reviewed = $establishment->isDirty(['type', 'commercial_register_no', 'tax_number']);
            if ($reviewed && $establishment->status === EstablishmentStatus::Approved) {
                $establishment->status = EstablishmentStatus::UnderReview;
                $establishment->submitted_at = now();
            }
            $establishment->save();

            return $establishment;
        });
    }

    /** Sends the establishment to the admin once every paper it needs is uploaded. */
    public function submit(Membership $actor): Establishment
    {
        $this->assertOwner($actor);
        $establishment = $actor->establishment;
        if (! in_array($establishment->status, [EstablishmentStatus::Draft, EstablishmentStatus::Rejected], true)) {
            throw new ProviderException('already_submitted');
        }
        $missing = $this->requirements->missing($establishment, DocumentSubject::Establishment, $establishment->id);
        if ($missing !== []) {
            throw new ProviderException('documents_missing', 422, [], ['missing' => $missing]);
        }
        // Moving is done with vehicles: at least one, sent with its photos, before the review (closing the app on the
        // registration's vehicle step must not skip it).
        $moving = $establishment->services()->where('module_name', 'moving')->exists();
        if ($moving && ! $establishment->vehicles()->where('status', '!=', VehicleStatus::Inactive->value)->exists()) {
            throw new ProviderException('vehicle_required');
        }

        $establishment->update(['status' => EstablishmentStatus::UnderReview, 'submitted_at' => now(), 'status_reason' => null]);

        return $establishment;
    }

    /**
     * @param  array{name: string, lat: float, lng: float, radius_km?: float|null, address?: string|null, status?: bool}  $data
     */
    public function saveBranch(Membership $actor, array $data, ?Branch $branch = null): Branch
    {
        $this->access->authorize($actor, 'branches.manage', $branch);
        if ($branch !== null && $branch->establishment_id !== $actor->establishment_id) {
            throw new ProviderException('not_found', 404);
        }

        if ($branch === null) {
            $data['radius_km'] ??= config('provider.default_radius_km', 100);

            return $actor->establishment->branches()->create($data);
        }

        // The main branch can't be switched off: an individual works from it.
        if ($branch->is_main) {
            unset($data['status']);
        }
        $branch->update($data);

        return $branch;
    }

    public function deleteBranch(Membership $actor, Branch $branch): void
    {
        $this->access->authorize($actor, 'branches.manage', $branch);
        if ($branch->establishment_id !== $actor->establishment_id) {
            throw new ProviderException('not_found', 404);
        }
        if ($branch->is_main) {
            throw new ProviderException('main_branch');
        }
        $branch->delete();
    }

    public function assertOwner(Membership $actor): void
    {
        if (! $actor->is_owner || ! $actor->isActive()) {
            throw new ProviderException('owner_only', 403);
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function validServices(array $ids): array
    {
        $valid = $this->offerableServices()->whereIn('id', $ids)->pluck('id')->all();
        if ($valid === [] || count($valid) !== count(array_unique($ids))) {
            throw new ProviderException('service_not_offerable');
        }

        return array_values($valid);
    }

    public static function isIndividual(Establishment $establishment): bool
    {
        return $establishment->type === EstablishmentType::Individual;
    }
}
