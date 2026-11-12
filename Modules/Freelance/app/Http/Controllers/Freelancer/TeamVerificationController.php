<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamVerification;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * FS-202b: a team's verification (D4, optional) under `freelance/v1/teams/{team}/verification`, behind the `teams` switch: the Owners read it,
 * upload papers, send them and open them again. Somebody who is not an Owner of the team gets 404, like the team's other private routes.
 */
class TeamVerificationController extends Controller
{
    public function __construct(
        private readonly FreelancerTeams $teams,
        private readonly TeamVerification $verification,
    ) {}

    /** GET teams/{team}/verification */
    public function show(Request $request, string $team): JsonResponse
    {
        return ApiResponse::success($this->verification->overview($this->owned($request, $team)), __('api.retrieved'));
    }

    /** POST teams/{team}/verification/documents (multipart: title, file) */
    public function upload(Request $request, string $team): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:'.TeamVerification::TITLE_MAX],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:'.(int) config('freelance.verification.max_file_kb', 8192), new \Modules\Freelance\Rules\CleanUpload],
        ]);
        $t = $this->owned($request, $team);
        $doc = $this->verification->upload($t, $this->me($request), $data['title'], $request->file('file'));

        return ApiResponse::created($this->verification->presentDocument($doc, $t), __('api.created'));
    }

    /** DELETE teams/{team}/verification/documents/{uuid} - a paper not sent yet. */
    public function destroy(Request $request, string $team, string $document): JsonResponse
    {
        $t = $this->owned($request, $team);
        $this->verification->deleteDraft($t, $this->me($request), $this->verification->documentFor($t, $document));

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** GET teams/{team}/verification/documents/{uuid} - the file, for an Owner only. */
    public function file(Request $request, string $team, string $document): BinaryFileResponse
    {
        $media = $this->verification->documentFor($this->owned($request, $team), $document)->file() ?? abort(404);

        // A company paper: no shared cache may keep it.
        return tap(response()->file($media->getPath(), ['Content-Type' => $media->mime_type]), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    /** POST teams/{team}/verification/submit */
    public function submit(Request $request, string $team): JsonResponse
    {
        $t = $this->owned($request, $team);
        $this->verification->submit($t, $this->me($request));

        return ApiResponse::success($this->verification->overview($t->refresh()), __('freelance.team_verification_sent'));
    }

    /** The team, for one of its Owners (404 otherwise). */
    private function owned(Request $request, string $uuid): FreelanceTeam
    {
        $team = FreelanceTeam::query()->where('uuid', $uuid)->firstOrFail();
        if (! $this->teams->isOwner($team, $this->me($request))) {
            throw new ModelNotFoundException;
        }

        return $team;
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
