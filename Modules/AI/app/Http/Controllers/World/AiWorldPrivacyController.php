<?php

namespace Modules\AI\Http\Controllers\World;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\AI\Exceptions\AiWorldException;
use Modules\AI\Models\World\AiWorldIdentityProfile;
use Modules\AI\Models\World\AiWorldProject;
use Modules\AI\Services\World\AiWorldAccess;
use Modules\AI\Services\World\AiWorldPrivacyService;

/** Reference photos, consent, deletion and reports. Every lookup is scoped to the signed-in owner. */
class AiWorldPrivacyController extends Controller
{
    public function __construct(protected AiWorldPrivacyService $privacy, protected AiWorldAccess $access) {}

    public function consentText()
    {
        return ApiResponse::success($this->privacy->consentText());
    }

    public function profiles(Request $request)
    {
        $profiles = AiWorldIdentityProfile::query()->where('owner_type', $request->user()->getMorphClass())->where('owner_id', $request->user()->getAuthIdentifier())
            ->where('status', '!=', AiWorldIdentityProfile::DELETED)->latest('id')->get();

        return ApiResponse::success($profiles->map(fn ($p) => $this->present($p))->all());
    }

    public function storeProfile(Request $request)
    {
        $this->access->assertEnabled();
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'subject_type' => ['required', 'string', 'in:self,other_with_permission'],
            'photos' => ['required', 'array', 'min:1', 'max:3'],
            'photos.*' => ['file', 'max:8192'],
        ]);

        $profile = $this->privacy->createProfile($request->user(), $data['label'], $data['subject_type'], $request->file('photos'));

        return ApiResponse::success($this->present($profile), 'OK', 201);
    }

    public function consent(Request $request, int $id)
    {
        $data = $request->validate(['attestation' => ['required', 'string', 'max:40']]);

        return ApiResponse::success($this->present($this->privacy->grantConsent($request->user(), $this->profile($request, $id), $data['attestation'], $request->ip())));
    }

    public function destroyProfile(Request $request, int $id)
    {
        $this->privacy->deleteProfile($request->user(), $this->profile($request, $id));

        return ApiResponse::success(null);
    }

    public function destroyProject(Request $request, int $id)
    {
        $this->privacy->deleteProject($request->user(), $this->project($request, $id));

        return ApiResponse::success(null);
    }

    public function report(Request $request, int $id)
    {
        $data = $request->validate(['type' => ['required', 'string', 'in:complaint,deletion,impersonation'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $report = $this->privacy->report($request->user(), $this->project($request, $id), $data['type'], $data['notes'] ?? null);

        return ApiResponse::success(['id' => $report->id, 'status' => $report->status], 'OK', 201);
    }

    /** Never exposes file paths: the app only needs to know how many photos exist. */
    protected function present(AiWorldIdentityProfile $p): array
    {
        return [
            'id' => $p->id,
            'label' => $p->label,
            'subject_type' => $p->subject_type,
            'status' => $p->status,
            'photos' => count((array) $p->reference_files),
            'expires_at' => $p->expires_at?->toIso8601String(),
        ];
    }

    protected function profile(Request $request, int $id): AiWorldIdentityProfile
    {
        return AiWorldIdentityProfile::query()->where('owner_type', $request->user()->getMorphClass())->where('owner_id', $request->user()->getAuthIdentifier())->find($id)
            ?? throw new AiWorldException('not_found', 404);
    }

    protected function project(Request $request, int $id): AiWorldProject
    {
        return AiWorldProject::query()->where('owner_type', $request->user()->getMorphClass())->where('owner_id', $request->user()->getAuthIdentifier())->find($id)
            ?? throw new AiWorldException('not_found', 404);
    }
}
