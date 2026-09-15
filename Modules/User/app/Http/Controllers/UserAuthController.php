<?php

namespace Modules\User\Http\Controllers;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\User\Http\Requests\UserLoginRequest;
use Modules\User\Http\Resources\UserResource;
use Modules\User\Models\User;

class UserAuthController extends Controller
{
    public function login(UserLoginRequest $request)
    {
        $user = User::query()
            ->where('email', $request->validated('email'))
            ->first();

        if (! $user || ! $user->password || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('api.invalid_credentials')],
            ]);
        }

        if ($user->status === UserStatus::Blocked) {
            return ApiResponse::error(__('api.account_blocked'), 403);
        }

        if ($user->status === UserStatus::Inactive) {
            return ApiResponse::error(__('api.account_inactive'), 403);
        }

        $user->tokens()->delete();

        $token = $user->createToken('user-api')->plainTextToken;

        return ApiResponse::success([
            'user' => new UserResource($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ], __('api.login_success'));
    }

    public function logout()
    {
        request()->user('user_api')?->currentAccessToken()?->delete();

        return ApiResponse::success([], __('api.logout_success'));
    }

    public function me()
    {
        /** @var User $user */
        $user = request()->user('user_api');
        $user->load(['country.flag']);

        return ApiResponse::success(
            new UserResource($user),
            __('api.retrieved'),
        );
    }

    public function checkToken(): JsonResponse
    {
        $token = request()->bearerToken() ?? request()->input('token');

        if (! $token) {
            return ApiResponse::error(__('api.token_invalid'), 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken || ! $accessToken->tokenable instanceof User) {
            return ApiResponse::error(__('api.token_invalid'), 401);
        }

        /** @var User $user */
        $user = $accessToken->tokenable;

        if ($user->status !== UserStatus::Active) {
            return ApiResponse::error(
                $user->status === UserStatus::Blocked
                    ? __('api.account_blocked')
                    : __('api.account_inactive'),
                403,
            );
        }

        return ApiResponse::success([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'valid' => true,
            'user' => new UserResource($user),
        ], __('api.token_valid'));
    }
}
