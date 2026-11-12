<?php

namespace Modules\Marketing\Enums;

/**
 * The project team (D2, spec 02 adapted to one project): what each role may do. Nobody here publishes or spends:
 * there is no publishing in phase A, and the AI only proposes.
 */
enum ProjectRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Analyst = 'analyst';
    case Viewer = 'viewer';

    /** Edit the profile and discovery, generate and edit plans. */
    public function canEdit(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }

    /** Start research runs. */
    public function canResearch(): bool
    {
        return in_array($this, [self::Owner, self::Manager, self::Analyst], true);
    }

    /** Approve a plan version or send it back (owner decision 2026-11-11: the manager approves too). */
    public function canApprove(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }

    /** Manage the team, archive plans, archive or delete the project. */
    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    /** @return list<string> roles the owner can give */
    public static function assignable(): array
    {
        return [self::Manager->value, self::Analyst->value, self::Viewer->value];
    }

    /** @return array<string, bool> */
    public function abilities(): array
    {
        return [
            'edit' => $this->canEdit(),
            'research' => $this->canResearch(),
            'approve' => $this->canApprove(),
            'manage_team' => $this->isOwner(),
            'approve_budget' => $this->isOwner(),
            'results' => $this->canResearch(),
        ];
    }
}
