<?php

namespace Modules\Provider\Services\Establishments;

use Illuminate\Support\Facades\DB;
use Modules\Provider\Enums\MembershipStatus;
use Modules\Provider\Events\MembershipEnded;
use Modules\Provider\Exceptions\ProviderException;
use Modules\Provider\Models\Branch;
use Modules\Provider\Models\Establishment;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\MembershipEvent;
use Modules\Provider\Models\Provider;
use Modules\Provider\Services\Notifications\ProviderNotifier;
use Modules\Provider\Support\ProviderPermissions;

/**
 * The team of an establishment (§14, §17): inviting people by phone with chosen permissions and branches,
 * accepting or declining, changing what they may do, removing them, and leaving at any time. Nobody can hand out a
 * permission or a branch they don't hold themselves, and the owner's seat can't be touched. Every change is logged.
 */
class MembershipService
{
    public function __construct(private readonly EstablishmentAccess $access) {}

    /**
     * @param  array{phone: string, permissions: list<string>, branch_ids?: list<int>|null, is_exclusive?: bool, offer_limit_minor?: int|null}  $data
     */
    public function invite(Membership $actor, array $data): Membership
    {
        $this->access->authorize($actor, 'members.manage');
        $establishment = $actor->establishment;
        $permissions = $this->grantable($actor, $data['permissions']);
        $branchIds = $this->grantableBranches($actor, $data['branch_ids'] ?? null);

        $person = Provider::query()->where('phone', $data['phone'])->first();
        $taken = $establishment->memberships()
            ->whereIn('status', [MembershipStatus::Invited->value, MembershipStatus::Active->value])
            ->where(fn ($q) => $q->where('phone', $data['phone'])->when($person, fn ($q) => $q->orWhere('provider_id', $person->id)))
            ->exists();
        if ($taken) {
            throw new ProviderException('already_member');
        }

        return DB::transaction(function () use ($actor, $establishment, $data, $person, $permissions, $branchIds): Membership {
            $membership = $establishment->memberships()->create([
                'provider_id' => $person?->id,
                'phone' => $data['phone'],
                'is_exclusive' => (bool) ($data['is_exclusive'] ?? false),
                'all_branches' => $branchIds === null,
                'offer_limit_minor' => $data['offer_limit_minor'] ?? null,
                'status' => MembershipStatus::Invited,
                'invited_by_id' => $actor->provider_id,
            ]);
            $this->syncPermissions($membership, $permissions);
            $membership->branches()->sync($branchIds ?? []);
            $this->log($membership, $actor->provider_id, 'invited', ['permissions' => $permissions, 'branch_ids' => $branchIds, 'is_exclusive' => $membership->is_exclusive]);
            DB::afterCommit(fn () => app(ProviderNotifier::class)->invited($membership));

            return $membership;
        });
    }

    /** An invitation sent to a phone before its owner had an account becomes theirs once the phone is verified. */
    public function claimInvitations(Provider $provider): void
    {
        if ($provider->phone === null) {
            return;
        }

        Membership::query()
            ->whereNull('provider_id')
            ->where('phone', $provider->phone)
            ->where('status', MembershipStatus::Invited->value)
            ->update(['provider_id' => $provider->id]);
    }

    public function accept(Provider $provider, Membership $membership): Membership
    {
        $this->assertInvitationFor($provider, $membership);
        $membership->update(['status' => MembershipStatus::Active, 'joined_at' => now()]);
        $this->log($membership, $provider->id, 'joined');

        return $membership;
    }

    public function decline(Provider $provider, Membership $membership): Membership
    {
        $this->assertInvitationFor($provider, $membership);
        $membership->update(['status' => MembershipStatus::Declined, 'ended_at' => now()]);
        $this->log($membership, $provider->id, 'declined');

        return $membership;
    }

    /**
     * @param  array{permissions?: list<string>, branch_ids?: list<int>|null, offer_limit_minor?: int|null, is_exclusive?: bool}  $data
     */
    public function update(Membership $actor, Membership $membership, array $data): Membership
    {
        $this->access->authorize($actor, 'members.manage');
        $this->assertManageable($actor, $membership);

        return DB::transaction(function () use ($actor, $membership, $data): Membership {
            $changes = [];
            if (array_key_exists('permissions', $data)) {
                $permissions = $this->grantable($actor, $data['permissions']);
                $changes['permissions'] = ['from' => $this->access->permissions($membership), 'to' => $permissions];
                $this->syncPermissions($membership, $permissions);
            }
            if (array_key_exists('branch_ids', $data)) {
                $branchIds = $this->grantableBranches($actor, $data['branch_ids']);
                $changes['branch_ids'] = ['to' => $branchIds];
                $membership->branches()->sync($branchIds ?? []);
                $membership->all_branches = $branchIds === null;
            }
            if (array_key_exists('offer_limit_minor', $data)) {
                $changes['offer_limit_minor'] = ['from' => $membership->offer_limit_minor, 'to' => $data['offer_limit_minor']];
                $membership->offer_limit_minor = $data['offer_limit_minor'];
            }
            // Exclusivity is agreed before joining; afterwards it can only be dropped, never added behind their back.
            if (array_key_exists('is_exclusive', $data) && ($membership->status === MembershipStatus::Invited || ! $data['is_exclusive'])) {
                $changes['is_exclusive'] = ['from' => $membership->is_exclusive, 'to' => (bool) $data['is_exclusive']];
                $membership->is_exclusive = (bool) $data['is_exclusive'];
            }
            $membership->save();
            $this->log($membership, $actor->provider_id, 'permissions_changed', $changes);

            return $membership;
        });
    }

    public function remove(Membership $actor, Membership $membership): Membership
    {
        $this->access->authorize($actor, 'members.manage');
        $this->assertManageable($actor, $membership);

        return $this->end($membership, MembershipStatus::Removed, $actor->provider_id, 'removed');
    }

    /** A member may leave whenever they like, without notice (owner's call, §14). The owner can't leave their own. */
    public function leave(Provider $provider, Membership $membership): Membership
    {
        if ($membership->provider_id !== $provider->id || ! $membership->isActive()) {
            throw new ProviderException('not_found', 404);
        }
        if ($membership->is_owner) {
            throw new ProviderException('owner_cannot_leave');
        }

        return $this->end($membership, MembershipStatus::Left, $provider->id, 'left');
    }

    /** @param list<string> $permissions */
    private function syncPermissions(Membership $membership, array $permissions): void
    {
        $membership->permissionRows()->delete();
        foreach ($permissions as $permission) {
            $membership->permissionRows()->create(['permission' => $permission]);
        }
    }

    private function end(Membership $membership, MembershipStatus $status, ?int $actorId, string $action): Membership
    {
        DB::transaction(function () use ($membership, $status, $actorId, $action): void {
            $membership->update(['status' => $status, 'ended_at' => now()]);
            $this->log($membership, $actorId, $action);
        });
        // Their upcoming jobs go back to the establishment to hand to someone else.
        event(new MembershipEnded($membership));

        return $membership;
    }

    /**
     * @param  array<int, mixed>  $requested
     * @return list<string>
     */
    private function grantable(Membership $actor, array $requested): array
    {
        $permissions = ProviderPermissions::clean($requested);
        $held = $this->access->permissions($actor);
        if (array_diff($permissions, $held) !== []) {
            throw new ProviderException('permission_not_held', 403);
        }

        return $permissions;
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return list<int>|null null = every branch
     */
    private function grantableBranches(Membership $actor, ?array $branchIds): ?array
    {
        $actorBranches = $this->access->branchIds($actor);
        if ($branchIds === null) {
            if ($actorBranches !== null) {
                throw new ProviderException('branch_not_held', 403);
            }

            return null;
        }

        $ids = Branch::query()->where('establishment_id', $actor->establishment_id)->whereIn('id', $branchIds)->pluck('id')->all();
        if (count($ids) !== count(array_unique($branchIds)) || ($actorBranches !== null && array_diff($ids, $actorBranches) !== [])) {
            throw new ProviderException('branch_not_held', 403);
        }

        return array_values($ids);
    }

    private function assertManageable(Membership $actor, Membership $membership): void
    {
        if ($membership->establishment_id !== $actor->establishment_id || ! $membership->status->isOpen()) {
            throw new ProviderException('not_found', 404);
        }
        if ($membership->is_owner || $membership->id === $actor->id) {
            throw new ProviderException('cannot_change_member', 403);
        }
        // A manager limited to some branches can only touch people limited to those branches.
        $actorBranches = $this->access->branchIds($actor);
        if ($actorBranches !== null) {
            $theirs = $this->access->branchIds($membership);
            if ($theirs === null || array_diff($theirs, $actorBranches) !== []) {
                throw new ProviderException('branch_not_held', 403);
            }
        }
    }

    private function assertInvitationFor(Provider $provider, Membership $membership): void
    {
        if ($membership->provider_id !== $provider->id || $membership->status !== MembershipStatus::Invited) {
            throw new ProviderException('not_found', 404);
        }
    }

    /** @param array<string, mixed> $changes */
    private function log(Membership $membership, ?int $actorId, string $action, array $changes = []): void
    {
        MembershipEvent::query()->create([
            'establishment_id' => $membership->establishment_id,
            'membership_id' => $membership->id,
            'actor_id' => $actorId,
            'action' => $action,
            'changes' => $changes ?: null,
        ]);
    }

    /** The owner's own seat, made with the establishment. */
    public function createOwner(Establishment $establishment, Provider $owner): Membership
    {
        $membership = $establishment->memberships()->create([
            'provider_id' => $owner->id,
            'phone' => $owner->phone,
            'is_owner' => true,
            'all_branches' => true,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $this->log($membership, $owner->id, 'joined');

        return $membership;
    }
}
