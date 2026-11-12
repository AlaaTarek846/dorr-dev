<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceConsultation as Consultation;
use Modules\Freelance\Models\FreelanceConsultationOffer as Listing;
use Modules\Freelance\Services\Consultations\ConsultationListings;
use Modules\Freelance\Services\Consultations\Consultations;

/**
 * Admin → Freelance → Consultations (FL-L07): the listings freelancers send for review (approve, reject, suspend, lift), and every booking
 * (the complaints first) with its money, and deciding a complaint — pay the freelancer or give the client everything back. The rules (free cancel hours, complaint hours, notice, days ahead) are in the
 * policies page.
 */
class FreelanceConsultationAdminController extends Controller implements HasMiddleware
{
    public function __construct(private readonly Consultations $consultations, private readonly ConsultationListings $listings) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-consultations', [
            ['view', ['index', 'show', 'offers', 'offer', 'session']],
            ['update', ['resolve', 'approve', 'reject', 'suspend', 'unsuspend', 'sessionAction']],
        ]);
    }

    /** GET freelance-consultations[?status=&search=&per_page=] — `counts` per status in the meta. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(Consultation::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->consultations->adminPage($f['status'] ?? null, $f['search'] ?? null, (int) ($f['per_page'] ?? 20));

        return ApiResponse::success(
            $page->getCollection()->map(fn ($c) => $this->consultations->present($c, 'staff'))->values(), __('api.retrieved'), 200,
            ApiPaginator::meta($page), ['counts' => $this->consultations->adminCounts()],
        );
    }

    public function show(string $uuid): JsonResponse
    {
        return ApiResponse::success($this->consultations->present($this->find($uuid), 'staff'), __('api.retrieved'));
    }

    /**
     * GET freelance-consultations/{uuid}/session (FL-L16) — the room, who came and left, the recordings with links to watch (made for this
     * admin and written down when used) and who has taken them.
     */
    public function session(Request $request, string $uuid): JsonResponse
    {
        $c = $this->find($uuid);
        $sessions = app(\Modules\Freelance\Services\Consultations\ConsultationSessions::class);

        return ApiResponse::success($sessions->presentFor($c, 'staff', (int) $request->user('admin_api')->id) + ['views' => $sessions->views($c)], __('api.retrieved'));
    }

    /** POST freelance-consultations/{uuid}/session/{close|reopen} — close the room now, or open again one the staff closed while time is left. */
    public function sessionAction(Request $request, string $uuid, string $action): JsonResponse
    {
        $sessions = app(\Modules\Freelance\Services\Consultations\ConsultationSessions::class);
        $c = $action === 'close' ? $sessions->closeByStaff($this->find($uuid)) : $sessions->reopenByStaff($this->find($uuid));

        return ApiResponse::success($sessions->presentFor($c, 'staff', (int) $request->user('admin_api')->id), __('api.updated'));
    }

    /** POST freelance-consultations/{uuid}/resolve {decision: pay|refund, note?} */
    public function resolve(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['pay', 'refund'])], 'note' => ['nullable', 'string', 'max:1000']]);
        $c = $this->consultations->resolve($this->find($uuid), $data['decision'], $data['note'] ?? null, (int) $request->user('admin_api')->id);

        return ApiResponse::success($this->consultations->present($c, 'staff'), __('api.updated'));
    }

    /** GET freelance-consultation-offers[?status=&search=&per_page=] — the listings (waiting for review first); `counts` per status. */
    public function offers(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(array_diff(Listing::STATUSES, [Listing::DRAFT]))],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->listings->adminPage($f['status'] ?? null, $f['search'] ?? null, (int) ($f['per_page'] ?? 20));

        return ApiResponse::success(
            $page->getCollection()->map(fn ($o) => $this->listings->present($o, 'staff'))->values(), __('api.retrieved'), 200,
            ApiPaginator::meta($page), ['counts' => $this->listings->adminCounts()],
        );
    }

    public function offer(string $uuid): JsonResponse
    {
        return $this->listing($this->listings->findForStaff($uuid));
    }

    public function approve(Request $request, string $uuid): JsonResponse
    {
        return $this->listing($this->listings->approve($this->listings->findForStaff($uuid), $this->admin($request)), __('api.updated'));
    }

    /** POST freelance-consultation-offers/{uuid}/reject {reason} */
    public function reject(Request $request, string $uuid): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->listing($this->listings->reject($this->listings->findForStaff($uuid), $d['reason'], $this->admin($request)), __('api.updated'));
    }

    /** POST freelance-consultation-offers/{uuid}/suspend {reason} */
    public function suspend(Request $request, string $uuid): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->listing($this->listings->suspend($this->listings->findForStaff($uuid), $d['reason'], $this->admin($request)), __('api.updated'));
    }

    public function unsuspend(Request $request, string $uuid): JsonResponse
    {
        return $this->listing($this->listings->unsuspend($this->listings->findForStaff($uuid), $this->admin($request)), __('api.updated'));
    }

    private function listing(Listing $o, ?string $message = null): JsonResponse
    {
        return ApiResponse::success($this->listings->present($o, 'staff'), $message ?? __('api.retrieved'));
    }

    private function admin(Request $request): int
    {
        return (int) $request->user('admin_api')->id;
    }

    private function find(string $uuid): Consultation
    {
        return Consultation::query()->where('uuid', $uuid)->with(['freelancer', 'client', 'offer'])->firstOrFail();
    }
}
