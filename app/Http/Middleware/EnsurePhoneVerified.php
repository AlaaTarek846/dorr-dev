<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePhoneVerified
{
    /**
     * Blocks requests while the authenticated users' phone_verified_at is null.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        /** @var User|null $user */
        $user = Auth::guard($guard)->user();

        if ($user && $user->phone_verified_at === null) {
            return ApiResponse::error(__('api.phone_not_verified'), 403);
        }

        return $next($request);
    }
}
