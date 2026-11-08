<?php

namespace Modules\Freelance\Services\Offers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Services\FreelancerTeam\TeamProfile;
use Modules\User\Models\User;

/**
 * FS-213: the freelancers' teams a client keeps, next to the freelancers he keeps (FL-306) - to find a team again after a project and invite
 * it to the next one. A client may save a team whose profile he may read (`TeamProfile::viewer`: a public team, or a private one that sent
 * him an offer), and the list shows only what that profile already shows. A team that is no longer readable (suspended, archived, turned
 * private) drops out of the list but stays saved, and comes back when it can be read again.
 */
class ClientTeamFavorites
{
    public function __construct(private readonly TeamProfile $profile) {}

    public function add(User $client, FreelanceTeam $team): void
    {
        DB::table('freelance_client_team_favorites')->insertOrIgnore(['user_id' => $client->id, 'team_id' => $team->id, 'created_at' => now()]);
    }

    public function remove(User $client, FreelanceTeam $team): void
    {
        DB::table('freelance_client_team_favorites')->where('user_id', $client->id)->where('team_id', $team->id)->delete();
    }

    public function has(User $client, int $teamId): bool
    {
        return DB::table('freelance_client_team_favorites')->where('user_id', $client->id)->where('team_id', $teamId)->exists();
    }

    /** A team this client may save (one whose profile he may read); 404 otherwise, as if there were no team. */
    public function findFor(User $client, string $uuid): FreelanceTeam
    {
        $team = FreelanceTeam::query()->where('uuid', $uuid)->first();
        if ($team === null || $this->profile->viewer($team, null, $client) !== TeamProfile::CLIENT) {
            throw new ModelNotFoundException;
        }

        return $team;
    }

    /** A saved team taken off the list keeps working for "remove" even when the client can no longer read it. */
    public function findSaved(User $client, string $uuid): FreelanceTeam
    {
        return FreelanceTeam::query()->where('uuid', $uuid)
            ->whereIn('id', DB::table('freelance_client_team_favorites')->where('user_id', $client->id)->select('team_id'))
            ->firstOrFail();
    }

    /** @return Collection<int, array<string, mixed>> newest saved first, the ones he may still read */
    public function list(User $client): Collection
    {
        $ids = DB::table('freelance_client_team_favorites')->where('user_id', $client->id)->orderByDesc('created_at')->pluck('team_id');
        $teams = FreelanceTeam::query()->whereIn('id', $ids)->get()->keyBy('id');
        $worked = Agreement::query()->whereIn('team_id', $ids)->whereHas('project', fn ($q) => $q->where('client_id', $client->id))
            ->whereIn('status', [Agreement::ACTIVE, Agreement::COMPLETED])->pluck('team_id')->map(fn ($id) => (int) $id)->unique()->all();

        return $ids->map(fn ($id) => $teams->get($id))->filter()
            ->filter(fn (FreelanceTeam $t) => $this->profile->viewer($t, null, $client) === TeamProfile::CLIENT)
            ->map(fn (FreelanceTeam $t) => [
                'uuid' => $t->uuid,
                'name' => $t->name,
                'worked_with' => in_array((int) $t->id, $worked, true),
            ] + $this->profile->summary($t))->values();
    }
}
