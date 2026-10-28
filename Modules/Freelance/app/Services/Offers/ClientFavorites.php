<?php

namespace Modules\Freelance\Services\Offers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Account\FreelancerCompleteness;
use Modules\User\Models\User;

/**
 * The freelancers a client keeps (FL-306), to find them again after a project: saved from an offer they received, shown with a mark on
 * later offers, and listed. Only a freelancer who sent this client an offer can be saved (no way to browse or reach others), and only
 * what an offer already shows of them is listed — never a phone or an email.
 */
class ClientFavorites
{
    public function __construct(private readonly FreelancerCompleteness $completeness) {}

    public function add(User $client, Freelancer $freelancer): void
    {
        DB::table('freelance_client_favorites')->insertOrIgnore(['user_id' => $client->id, 'freelancer_id' => $freelancer->id, 'created_at' => now()]);
    }

    public function remove(User $client, Freelancer $freelancer): void
    {
        DB::table('freelance_client_favorites')->where('user_id', $client->id)->where('freelancer_id', $freelancer->id)->delete();
    }

    public function has(User $client, int $freelancerId): bool
    {
        return DB::table('freelance_client_favorites')->where('user_id', $client->id)->where('freelancer_id', $freelancerId)->exists();
    }

    /** A freelancer this client may save: one who sent an offer on one of their projects. */
    public function findFor(User $client, string $uuid): Freelancer
    {
        return Freelancer::query()->where('uuid', $uuid)
            ->whereHas('offers', fn ($q) => $q->whereHas('project', fn ($p) => $p->where('client_id', $client->id)))
            ->firstOrFail();
    }

    /** @return Collection<int, array<string, mixed>> newest saved first */
    public function list(User $client): Collection
    {
        $ids = DB::table('freelance_client_favorites')->where('user_id', $client->id)->orderByDesc('created_at')->pluck('freelancer_id');
        $people = Freelancer::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn ($id) => $people->get($id))->filter()->map(fn (Freelancer $f) => [
            'uuid' => $f->uuid,
            'name' => $f->name,
            'headline' => $f->headline,
            'avatar_url' => $f->getFirstMediaUrl('avatar') ?: null,
            'verified' => $f->status === FreelancerStatus::Verified,
            'completeness' => $this->completeness->of($f)['percent'],
        ])->values();
    }
}
