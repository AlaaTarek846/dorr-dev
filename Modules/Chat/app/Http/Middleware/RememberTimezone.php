<?php

namespace Modules\Chat\Http\Middleware;

use Closure;
use DateTimeZone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app sends its phone's zone (`X-Timezone: Asia/Riyadh`); it's kept on the account when it
 * changes — what a greeting scheduled "at midnight for them" is planned in (spec 164).
 */
class RememberTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        $zone = $request->header('X-Timezone');
        $user = $request->user();

        // Users only (the column lives on `users`).
        if (is_string($zone) && $zone !== '' && $user instanceof \Modules\User\Models\User && $user->timezone !== $zone
            && in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            $user->forceFill(['timezone' => $zone])->saveQuietly();
        }

        return $next($request);
    }
}
