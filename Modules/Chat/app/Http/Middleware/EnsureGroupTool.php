<?php

namespace Modules\Chat\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatSetting;
use Symfony\Component\HttpFoundation\Response;

/**
 * An extra tool of educational / work groups the admin switched off for everyone (chat settings →
 * `group_tools_disabled`, docs/groups_tasks.md GR-051) answers `403 chat_group_tool_disabled`.
 */
class EnsureGroupTool
{
    /** Every tool that can be switched off, by kind. */
    public const TOOLS = [
        'education' => ['lessons', 'assignments', 'quizzes', 'sessions', 'bag', 'stars', 'reading', 'certificates', 'surveys', 'parents'],
        'work' => ['tasks', 'shifts', 'competitions', 'badges', 'ideas', 'meetings'],
        'both' => ['announcements', 'help', 'polls', 'spaces'],
    ];

    public static function all(): array
    {
        return array_merge(...array_values(self::TOOLS));
    }

    public static function disabled(string $tool): bool
    {
        return in_array($tool, (array) (ChatSetting::current()->group_tools_disabled ?? []), true);
    }

    public function handle(Request $request, Closure $next, string $tool): Response
    {
        if (self::disabled($tool)) {
            throw new ChatException('group_tool_disabled', 403);
        }

        return $next($request);
    }
}
