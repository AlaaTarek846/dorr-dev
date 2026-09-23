<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Http\Requests\AdminLoginRequest;
use Modules\Admin\Http\Resources\AdminResource;
use Modules\Admin\Models\Admin;

class AdminAuthController extends Controller
{
    /**
     * @return list<string>
     */
    protected function adminAuthRelations(): array
    {
        return [
            'country.flag',
            'services.category.translations',
            'services.category.translation',
        ];
    }

    protected function adminAuthResource(Admin $admin): AdminResource
    {
        request()->attributes->set(AdminResource::INCLUDE_PERMISSIONS_ATTRIBUTE, true);

        return new AdminResource($admin);
    }

    public function login(AdminLoginRequest $request)
    {
        $admin = Admin::query()
            ->where('email', $request->validated('email'))
            ->first();

        if (! $admin || ! Hash::check($request->validated('password'), $admin->password)) {
            throw ValidationException::withMessages([
                'email' => [__('api.invalid_credentials')],
            ]);
        }

        if (! $admin->status) {
            return ApiResponse::error(__('api.account_inactive'), 403);
        }

        $admin->tokens()->delete();

        $token = $admin->createToken('admin-api')->plainTextToken;

        $admin->load($this->adminAuthRelations());

        return ApiResponse::success([
            'admin' => $this->adminAuthResource($admin),
            'token' => $token,
            'token_type' => 'Bearer',
        ], __('api.login_success'));
    }

    public function logout()
    {
        request()->user('admin_api')?->currentAccessToken()?->delete();

        return ApiResponse::success([], __('api.logout_success'));
    }

    public function me()
    {
        /** @var Admin|null $admin */
        $admin = request()->user('admin_api');
        $admin?->load($this->adminAuthRelations());

        return ApiResponse::success(
            $this->adminAuthResource($admin),
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

        if (! $accessToken || ! $accessToken->tokenable instanceof Admin) {
            return ApiResponse::error(__('api.token_invalid'), 401);
        }

        /** @var Admin $admin */
        $admin = $accessToken->tokenable;

        if (! $admin->status) {
            return ApiResponse::error(__('api.account_inactive'), 403);
        }

        return ApiResponse::success([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'valid' => true,
            'admin' => $this->adminAuthResource($admin->load($this->adminAuthRelations())),
        ], __('api.token_valid'));
    }
}
