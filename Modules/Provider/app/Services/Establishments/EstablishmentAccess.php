<?php

namespace Modules\Provider\Services\Establishments;

use Illuminate\Http\Request;
use Modules\Provider\Enums\MembershipStatus;
use Modules\Provider\Exceptions\ProviderException;
use Modules\Provider\Models\Branch;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Provider;
use Modules\Provider\Support\ProviderPermissions;

/**
 * Who is acting, in which establishment, with which permissions (§17). The app sends the establishment it is
 * working in as `X-Establishment` (its uuid); with one membership the header may be left out. The server checks
 * every permission here, never only by hiding a button (REQ-002, REQ-087).
 */
class EstablishmentAccess
{
    public const HEADER = 'X-Establishment';

    public function current(Request $request): Membership
    {
        /** @var Provider $provider */
        $provider = $request->user('provider_api');
        $uuid = $request->header(self::HEADER);

        $query = $provider->memberships()->where('status', MembershipStatus::Active->value)->with('establishment');
        if (is_string($uuid) && $uuid !== '') {
            $query->whereHas('establishment', fn ($q) => $q->where('uuid', $uuid));
        }

        $memberships = $query->limit(2)->get();
        if ($memberships->isEmpty()) {
            throw new ProviderException('no_establishment', 404);
        }
        if ($memberships->count() > 1) {
            throw new ProviderException('pick_establishment', 409);
        }

        return $memberships->first();
    }

    /** @return list<string> */
    public function permissions(Membership $membership): array
    {
        if ($membership->is_owner) {
            return ProviderPermissions::all();
        }

        return $membership->permissionRows()->pluck('permission')->all();
    }

    public function can(Membership $membership, string $permission, ?Branch $branch = null): bool
    {
        if (! $membership->isActive()) {
            return false;
        }
        if ($membership->is_owner) {
            return true;
        }
        if (! in_array($permission, $this->permissions($membership), true)) {
            return false;
        }

        return $branch === null || $this->coversBranch($membership, $branch);
    }

    public function authorize(Membership $membership, string $permission, ?Branch $branch = null): void
    {
        if (! $this->can($membership, $permission, $branch)) {
            throw new ProviderException('forbidden', 403);
        }
    }

    public function coversBranch(Membership $membership, Branch $branch): bool
    {
        if ($branch->establishment_id !== $membership->establishment_id) {
            return false;
        }

        return $membership->is_owner || $membership->all_branches
            || $membership->branches()->whereKey($branch->id)->exists();
    }

    /** @return list<int>|null null = every branch */
    public function branchIds(Membership $membership): ?array
    {
        if ($membership->is_owner || $membership->all_branches) {
            return null;
        }

        return $membership->branches()->pluck('provider_branches.id')->all();
    }
}
