<?php

namespace Modules\Freelance\Services\Team;

use Modules\Freelance\Models\FreelanceProjectMember as Member;
use Modules\User\Models\User;

/**
 * FS-104: during one request, the team member acting for a project's owner. The request runs as the owner (his project, his wallet,
 * his checks); this remembers who really acted so every record row can say so. Empty when the owner acts himself.
 */
final class ClientTeamContext
{
    private ?Member $member = null;

    private ?User $actor = null;

    public function set(Member $member, User $actor): void
    {
        $this->member = $member;
        $this->actor = $actor;
    }

    public function clear(): void
    {
        $this->member = null;
        $this->actor = null;
    }

    public function member(): ?Member
    {
        return $this->member;
    }

    public function actor(): ?User
    {
        return $this->actor;
    }

    /** The id to write as `acted_by_user_id`, or null when nobody acted for someone else. */
    public static function actorId(): ?int
    {
        $actor = app(self::class)->actor();

        return $actor === null ? null : (int) $actor->getKey();
    }
}
