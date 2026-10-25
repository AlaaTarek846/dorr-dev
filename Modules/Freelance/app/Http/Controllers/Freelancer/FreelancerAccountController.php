<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Http\Resources\FreelancerResource;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerDevice;
use Modules\Freelance\Services\Account\FreelancerAuthService;
use Modules\Freelance\Services\Account\FreelancerProfileService;

/** The freelancer's own account: profile, avatar, terms, devices, deleting the account (FL-003). */
class FreelancerAccountController extends Controller
{
    public function __construct(
        private readonly FreelancerProfileService $profile,
        private readonly FreelancerAuthService $auth,
    ) {}

    public function updateProfile(Request $request): JsonResponse
    {
        $freelancer = $this->freelancer($request);
        $data = $request->validate(FreelancerProfileService::profileRules($freelancer));

        return ApiResponse::success(new FreelancerResource($this->profile->update($freelancer, $data)), __('api.updated'));
    }

    /** POST profile/email/send-code {email} — a code goes to that address. */
    public function sendEmailCode(Request $request): JsonResponse
    {
        $freelancer = $this->freelancer($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:255', Rule::unique('freelancers', 'email')->ignore($freelancer->id)]]);

        return ApiResponse::success($this->profile->requestEmailChange($freelancer, $data['email']), __('api.email_otp_sent'));
    }

    /** POST profile/email/confirm {code} — the code makes that address the freelancer's verified email. */
    public function confirmEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:'.max(4, (int) config('auth_flow.otp_length', 4))]]);

        return ApiResponse::success(new FreelancerResource($this->profile->confirmEmailChange($this->freelancer($request), $data['code'])), __('api.email_verified'));
    }

    public function avatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'max:5120', new \Modules\Freelance\Rules\CleanUpload]]);

        return ApiResponse::success(new FreelancerResource($this->profile->avatar($this->freelancer($request), $request->file('avatar'))), __('api.updated'));
    }

    public function acceptTerms(Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'max:20']]);

        return ApiResponse::success(new FreelancerResource($this->profile->acceptTerms($this->freelancer($request), $data['version'])), __('api.updated'));
    }

    public function devices(Request $request): JsonResponse
    {
        $freelancer = $this->freelancer($request);
        $current = $freelancer->currentAccessToken()?->id;
        $rows = $freelancer->devices()->latest('last_active_at')->get()->map(fn (FreelancerDevice $d) => [
            'id' => $d->id,
            'device_name' => $d->device_name,
            'platform' => $d->platform,
            'ip' => $d->ip,
            'last_active_at' => $d->last_active_at?->toIso8601String(),
            'signed_in_at' => $d->created_at?->toIso8601String(),
            'is_current' => $d->token_id !== null && $d->token_id === $current,
        ])->values();

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function destroyDevice(Request $request, int $device): JsonResponse
    {
        $freelancer = $this->freelancer($request);
        $this->auth->endDevices([$freelancer->devices()->findOrFail($device)]);

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function destroyAccount(Request $request): JsonResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $this->profile->delete($this->freelancer($request));

        return ApiResponse::success(null, __('api.deleted'));
    }

    private function freelancer(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
