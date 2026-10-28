<?php

namespace Modules\Freelance\Events;

/**
 * Two skills were merged (FL-102): everything that points at `$sourceId` must point at `$targetId` from now on
 * (a freelancer's skills in FL-104, a project's in batch 2). Each of those tasks listens and moves its own rows
 * without duplicating, so the catalog doesn't need to know them.
 */
class FreelanceSkillsMerged
{
    public function __construct(
        public readonly int $sourceId,
        public readonly int $targetId,
    ) {}
}
