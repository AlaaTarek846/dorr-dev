<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerCertificate;
use Modules\Freelance\Models\FreelancerExperience;
use Modules\Freelance\Services\Account\FreelancerProfileLists;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The freelancer's own lists (FL-104): specialties, skills, work history and certificates. Everything is scoped to the
 * signed-in freelancer — another one's row answers 404.
 */
class FreelancerProfileListsController extends Controller
{
    public function __construct(private readonly FreelancerProfileLists $lists) {}

    // -------------------------------------------------------------- specialties & skills

    public function specialties(Request $request): JsonResponse
    {
        return ApiResponse::success($this->lists->specialties($this->me($request)), __('api.retrieved'));
    }

    /** PUT profile/specialties {specialty_ids[], primary_id} */
    public function setSpecialties(Request $request): JsonResponse
    {
        $data = $request->validate([
            'specialty_ids' => ['required', 'array', 'min:1'],
            'specialty_ids.*' => ['integer', 'distinct'],
            'primary_id' => ['required', 'integer'],
        ]);

        return ApiResponse::success($this->lists->replaceSpecialties($this->me($request), $data['specialty_ids'], (int) $data['primary_id']), __('api.updated'));
    }

    public function skills(Request $request): JsonResponse
    {
        return ApiResponse::success($this->lists->skills($this->me($request)), __('api.retrieved'));
    }

    /** PUT profile/skills {skills: [{skill_id, level?}]} — the whole list (an empty one clears it). */
    public function setSkills(Request $request): JsonResponse
    {
        $data = $request->validate([
            'skills' => ['present', 'array'],
            'skills.*.skill_id' => ['required', 'integer'],
            'skills.*.level' => ['nullable', Rule::in(FreelancerProfileLists::LEVELS)],
        ]);

        return ApiResponse::success($this->lists->replaceSkills($this->me($request), $data['skills']), __('api.updated'));
    }

    // -------------------------------------------------------------- work history

    public function experiences(Request $request): JsonResponse
    {
        $rows = $this->me($request)->experiences()->orderByDesc('is_current')->orderByDesc('started_on')->orderByDesc('id')->get();

        return ApiResponse::success($rows->map(fn (FreelancerExperience $e) => $this->presentExperience($e))->values(), __('api.retrieved'));
    }

    public function storeExperience(Request $request): JsonResponse
    {
        $experience = $this->lists->addExperience($this->me($request), $request->validate($this->experienceRules(true)));

        return ApiResponse::created($this->presentExperience($experience), __('api.created'));
    }

    public function updateExperience(Request $request, int $experience): JsonResponse
    {
        $model = $this->me($request)->experiences()->findOrFail($experience);
        $data = $request->validate($this->experienceRules(false, $model));

        return ApiResponse::success($this->presentExperience($this->lists->updateExperience($model, $data)), __('api.updated'));
    }

    public function destroyExperience(Request $request, int $experience): JsonResponse
    {
        $this->me($request)->experiences()->findOrFail($experience)->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    // -------------------------------------------------------------- certificates

    public function certificates(Request $request): JsonResponse
    {
        $rows = $this->me($request)->certificates()->with('media')->orderByDesc('issued_on')->orderByDesc('id')->get();

        return ApiResponse::success($rows->map(fn (FreelancerCertificate $c) => $this->presentCertificate($c))->values(), __('api.retrieved'));
    }

    /** POST profile/certificates (multipart when it carries a `proof` file) */
    public function storeCertificate(Request $request): JsonResponse
    {
        $data = $request->validate($this->certificateRules(true));
        $certificate = $this->lists->addCertificate($this->me($request), $data, $request->file('proof'));

        return ApiResponse::created($this->presentCertificate($certificate), __('api.created'));
    }

    /** POST profile/certificates/{id} — `proof` replaces the file, `remove_proof=1` drops it (POST so a multipart body works). */
    public function updateCertificate(Request $request, int $certificate): JsonResponse
    {
        $model = $this->me($request)->certificates()->findOrFail($certificate);
        $data = $request->validate($this->certificateRules(false, $model));
        $updated = $this->lists->updateCertificate($model, $data, $request->file('proof'), $request->boolean('remove_proof'));

        return ApiResponse::success($this->presentCertificate($updated), __('api.updated'));
    }

    public function destroyCertificate(Request $request, int $certificate): JsonResponse
    {
        $this->lists->deleteCertificate($this->me($request)->certificates()->findOrFail($certificate));

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** GET profile/certificates/{id}/proof — the file, for its owner only. */
    public function proof(Request $request, int $certificate): BinaryFileResponse
    {
        $media = $this->me($request)->certificates()->findOrFail($certificate)->proof();
        abort_if($media === null, 404);

        return tap(response()->download($media->getPath(), $media->file_name), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    // -------------------------------------------------------------- shape

    /** @return array<string, mixed> */
    private function experienceRules(bool $creating, ?FreelancerExperience $current = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'min:2', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
            'started_on' => [$req, 'date', 'before_or_equal:today'],
            'ended_on' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.($this->input('started_on') ?? $current?->started_on?->toDateString() ?? '1900-01-01')],
            'is_current' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    private function certificateRules(bool $creating, ?FreelancerCertificate $current = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'min:2', 'max:150'],
            'issuer' => [$req, 'string', 'min:2', 'max:150'],
            'issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:'.($this->input('issued_on') ?? $current?->issued_on?->toDateString() ?? '1900-01-01')],
            'credential_url' => ['nullable', 'url:http,https', 'max:255'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120', new \Modules\Freelance\Rules\CleanUpload],
            'remove_proof' => ['sometimes', 'boolean'],
        ];
    }

    /** The body value of a field being validated (for rules that compare two dates). */
    private function input(string $key): ?string
    {
        $value = request()->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private function presentExperience(FreelancerExperience $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'company' => $e->company,
            'started_on' => $e->started_on?->toDateString(),
            'ended_on' => $e->ended_on?->toDateString(),
            'is_current' => (bool) $e->is_current,
            'description' => $e->description,
        ];
    }

    /** @return array<string, mixed> */
    private function presentCertificate(FreelancerCertificate $c): array
    {
        $proof = $c->proof();

        return [
            'id' => $c->id,
            'title' => $c->title,
            'issuer' => $c->issuer,
            'issued_on' => $c->issued_on?->toDateString(),
            'expires_on' => $c->expires_on?->toDateString(),
            'is_expired' => $c->expires_on !== null && $c->expires_on->isPast(),
            'credential_url' => $c->credential_url,
            // Not a public link: the file is downloaded from the endpoint below, signed in.
            'proof' => $proof === null ? null : ['name' => $proof->file_name, 'mime' => $proof->mime_type, 'size' => $proof->size, 'path' => "profile/certificates/{$c->id}/proof"],
        ];
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
