<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Projects\ProjectInvitations;

/** My invitations to private projects (FL-205): GET invitations[?status=sent|accepted|declined], POST invitations/{uuid}/accept|decline (a verified account). */
class FreelancerInvitationController extends Controller
{
    public function __construct(private readonly ProjectInvitations $invitations) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:sent,accepted,declined']])['status'] ?? null;
        $page = $this->invitations->pageFor($this->me($request), ApiPaginator::perPage(), $status);

        return ApiResponse::fromPaginator($page, $page->getCollection()->values(), __('api.retrieved'));
    }

    public function respond(Request $request, string $uuid, string $action): JsonResponse
    {
        $this->invitations->respond($this->me($request), $uuid, $action === 'accept');

        return ApiResponse::success($this->invitations->forFreelancer($this->me($request)), __('api.updated'));
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
