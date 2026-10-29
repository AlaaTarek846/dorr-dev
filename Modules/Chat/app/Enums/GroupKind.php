<?php

namespace Modules\Chat\Enums;

/**
 * What a group is for, picked when it's made and never changed (docs/groups_tasks.md GR-001):
 * an ordinary group, an educational group (teacher and students) or a work group (a team). The
 * kind decides which extra tools the group gets; an ordinary group works exactly as before.
 */
enum GroupKind: string
{
    case Normal = 'normal';
    case Education = 'education';
    case Work = 'work';

    /** Its switch in ChatFeatures (the admin's on/off, and the rules per country and city). */
    public function feature(): ?string
    {
        return match ($this) {
            self::Normal => null,
            self::Education => 'education_groups',
            self::Work => 'work_groups',
        };
    }
}
