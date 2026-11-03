<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceConsultation as Consultation;
use Modules\Freelance\Models\FreelanceConsultationRecording as Recording;
use Modules\Freelance\Services\Consultations\Consultations;
use Modules\Freelance\Services\Consultations\ConsultationSessions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The consultation's video session for both sides (FL-L16): the client under `mobile/v1/freelance/consultations/{uuid}/session`, the
 * freelancer under `freelance/v1/consultations/{uuid}/session`. A ticket into the room, saying the app is in it and that it left, the
 * client's "the freelancer did not come", and the recordings' signed links.
 */
class ConsultationSessionController extends Controller
{
    public function __construct(private readonly ConsultationSessions $sessions, private readonly Consultations $consultations) {}

    /** GET …/consultations/{uuid}/session — the room's state, who came, and the recordings with their links. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        [$c, $side] = $this->context($request, $uuid);

        return ApiResponse::success($this->sessions->presentFor($c, $side), __('api.retrieved'));
    }

    /** POST …/session/join — `{url, token, room, identity, starts_at, ends_at, server_time, warn_before_end_minutes, recording, other}` */
    public function join(Request $request, string $uuid): JsonResponse
    {
        [$c, $side] = $this->context($request, $uuid);

        return ApiResponse::success($this->sessions->join($c, $side, $this->person($request)), __('api.retrieved'));
    }

    /** POST …/session/connected — the app is in the room now. */
    public function connected(Request $request, string $uuid): JsonResponse
    {
        [$c, $side] = $this->context($request, $uuid);

        return ApiResponse::success($this->sessions->connected($c, $side), __('api.updated'));
    }

    /** POST …/session/leave — the app left the room. */
    public function leave(Request $request, string $uuid): JsonResponse
    {
        [$c, $side] = $this->context($request, $uuid);

        return ApiResponse::success($this->sessions->leave($c, $side), __('api.updated'));
    }

    /** POST mobile/v1/freelance/consultations/{uuid}/no-show — the client: the freelancer did not come (a complaint for the staff). */
    public function noShow(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user('user_api');
        $c = $this->sessions->noShow($this->consultations->forClient($me, $uuid), $me);

        return ApiResponse::success($this->consultations->present($c, 'client'), __('freelance.sessions.no_show_sent'));
    }

    /**
     * GET consultation-recordings/{recording}?viewer=&viewer_id=&action=download|watch (signed, short-lived) — the part from our disk.
     * The signature carries who it was made for; each use is written down.
     */
    public function file(Request $request, string $recording): BinaryFileResponse
    {
        $r = Recording::query()->where('uuid', $recording)->with('consultation')->firstOrFail();
        $viewer = (string) $request->query('viewer');
        abort_unless(in_array($viewer, ['client', 'freelancer', 'staff'], true), 404);
        $action = $request->query('action') === 'watch' ? 'watch' : 'download';
        $path = $this->sessions->file($r, $viewer, (int) $request->query('viewer_id'), $action);
        $name = 'consultation-'.substr((string) $r->consultation?->uuid, 0, 8).'-part-'.$r->part.'.mp4';
        $headers = ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        return $action === 'watch'
            ? response()->file($path, $headers + ['Content-Disposition' => 'inline; filename="'.$name.'"'])
            : response()->download($path, $name, $headers);
    }

    // -------------------------------------------------------------- inside

    /** @return array{0: Consultation, 1: 'client'|'freelancer'} */
    private function context(Request $request, string $uuid): array
    {
        if (($f = $request->user('freelance_api')) !== null && $request->is('api/freelance/*')) {
            return [$this->consultations->forFreelancer($f, $uuid), 'freelancer'];
        }

        return [$this->consultations->forClient($request->user('user_api'), $uuid), 'client'];
    }

    private function person(Request $request): \Illuminate\Database\Eloquent\Model
    {
        return $request->is('api/freelance/*') ? $request->user('freelance_api') : $request->user('user_api');
    }
}
