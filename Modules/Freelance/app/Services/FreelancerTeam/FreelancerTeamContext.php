<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;

/**
 * FS-206a: during one request, the person of a freelancers' team working on a team's project for the Owner who signed it. The request runs
 * as that Owner (his agreement, his wallet, his checks); this remembers who really acted, so every record row can say so
 * (`acted_by_freelancer_id`). Empty when the signing Owner acts himself. The twin of ClientTeamContext on the client's side.
 */
final class FreelancerTeamContext
{
    private ?Member $member = null;

    private ?Freelancer $actor = null;

    private ?string $role = null;

    public function set(Member $member, Freelancer $actor, string $role): void
    {
        $this->member = $member;
        $this->actor = $actor;
        $this->role = $role;
    }

    public function clear(): void
    {
        $this->member = null;
        $this->actor = null;
        $this->role = null;
    }

    public function member(): ?Member
    {
        return $this->member;
    }

    public function actor(): ?Freelancer
    {
        return $this->actor;
    }

    /** owner | pm | member - the person's place on this project (TeamProjects::ROLES). */
    public function role(): ?string
    {
        return $this->role;
    }

    /**
     * How a record row names the person of the team who acted (`by`): his name, and only the first name to the client (D10).
     *
     * @param  iterable<int|null>  $ids  the rows' `acted_by_freelancer_id`
     * @return \Closure(?int): (array{name: string, team: true}|null)
     */
    public static function namer(iterable $ids, bool $firstNameOnly): \Closure
    {
        $names = Freelancer::query()->whereIn('id', collect($ids)->filter()->unique()->values())->pluck('name', 'id');

        return function (?int $id) use ($names, $firstNameOnly): ?array {
            if ($id === null) {
                return null;
            }
            $name = (string) ($names[$id] ?? '');

            return ['name' => $firstNameOnly ? (strtok($name, ' ') ?: $name) : $name, 'team' => true];
        };
    }

    /** The id to write as `acted_by_freelancer_id`, or null when nobody acted for someone else. */
    public static function actorId(): ?int
    {
        $actor = app(self::class)->actor();

        return $actor === null ? null : (int) $actor->getKey();
    }
}
