<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Services\Projects\Agreements;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\User\Models\User;

/** FL-401, the client's side: GET|PUT projects/{uuid}/agreement, POST …/agreement/send, POST …/agreement/cancel. */
class ClientAgreementController extends Controller
{
    public function __construct(private readonly Agreements $agreements, private readonly ClientProjects $projects) {}

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->answer($this->find($request, $uuid));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['sometimes', 'required', 'string', 'max:8000'],
            'includes' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'excludes' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ]);

        return $this->answer($this->agreements->update($this->find($request, $uuid), $this->me($request), $data), __('api.updated'));
    }

    /** PUT projects/{uuid}/agreement/milestones {milestones: [{title, description?, amount_minor, duration_days?}]} — the whole list. */
    public function milestones(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'milestones' => ['required', 'array', 'min:1', 'max:50'],
            'milestones.*.title' => ['required', 'string', 'max:120'],
            'milestones.*.description' => ['nullable', 'string', 'max:500'],
            'milestones.*.amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'milestones.*.duration_days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('freelance.projects.max_duration_days', 365)],
        ]);

        return $this->answer($this->agreements->setMilestones($this->find($request, $uuid), $this->me($request), $data['milestones']), __('api.updated'));
    }

    /** POST projects/{uuid}/milestones/{milestone}/fund (FL-501) | approve | revision {reason} | cancel (FL-403). */
    public function milestoneAction(Request $request, string $uuid, string $milestone, string $action): JsonResponse
    {
        $data = $request->validate(['reason' => [$action === 'revision' ? 'required' : 'nullable', 'string', 'min:5', 'max:1000']]);
        $me = $this->me($request);
        $work = app(\Modules\Freelance\Services\Projects\MilestoneWork::class);
        $m = $work->find($this->projects->find($me, $uuid), $milestone);
        $cancel = app(\Modules\Freelance\Services\Projects\MilestoneCancellation::class);
        match ($action) {
            // FL-501: held from the wallet.
            'fund' => app(\Modules\Freelance\Services\Projects\MilestonePayments::class)->fund($m, $me),
            'approve' => $work->approve($m, $me),
            'revision' => $work->requestRevision($m, $me, $data['reason']),
            'cancel-accept' => $cancel->accept($m, $me, 'client'),
            'cancel-reject' => $cancel->reject($m, $me, 'client'),
            default => $cancel->byClient($m, $me, $data['reason'] ?? null),
        };

        return $this->answer($m->agreement, __('api.updated'));
    }

    public function send(Request $request, string $uuid): JsonResponse
    {
        return $this->answer($this->agreements->send($this->find($request, $uuid), $this->me($request)), __('freelance.agreement_sent'));
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        return $this->answer($this->agreements->cancel($this->find($request, $uuid), $this->me($request)), __('api.updated'));
    }

    private function find(Request $request, string $uuid): FreelanceAgreement
    {
        return $this->agreements->current($this->projects->find($this->me($request), $uuid)) ?? abort(404);
    }

    private function answer(FreelanceAgreement $a, ?string $message = null): JsonResponse
    {
        return ApiResponse::success($this->agreements->present($a->refresh()->load('freelancer'), 'client'), $message ?? __('api.retrieved'));
    }

    private function me(Request $request): User
    {
        return $request->user('user_api');
    }
}
