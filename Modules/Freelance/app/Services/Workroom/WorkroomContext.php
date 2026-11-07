<?php

namespace Modules\Freelance\Services\Workroom;

use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceWorkroomTask as Task;
use Modules\Freelance\Models\Freelancer;
use Modules\User\Models\User;

/** FS-150a: who is using a project's Workroom, as which side, and on which agreement. */
final class WorkroomContext
{
    public function __construct(
        public readonly FreelanceProject $project,
        public readonly FreelanceAgreement $agreement,
        public readonly string $side,
        public readonly User|Freelancer $actor,
    ) {}

    /** The side that is not mine. */
    public function otherSide(): string
    {
        return $this->side === Task::CLIENT ? Task::FREELANCER : Task::CLIENT;
    }

    /** Tasks can be added and changed only while the agreement is active; after it they are only read. */
    public function writable(): bool
    {
        return $this->agreement->status === FreelanceAgreement::ACTIVE;
    }
}
