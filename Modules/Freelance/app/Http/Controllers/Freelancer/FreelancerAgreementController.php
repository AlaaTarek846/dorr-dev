<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Projects\Agreements;
use Modules\Freelance\Services\Projects\FreelancerProjects;

/** FL-401, the freelancer's side: GET projects/{uuid}/agreement, POST …/agreement/approve, POST …/agreement/decline {reason?}. */
class FreelancerAgreementController extends Controller
{
    public function __construct(private readonly Agreements $agreements, private readonly FreelancerProjects $projects) {}

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->answer($this->find($request, $uuid));
    }

    public function approve(Request $request, string $uuid): JsonResponse
    {
        return $this->answer($this->agreements->approve($this->find($request, $uuid), $this->me($request)), __('freelance.agreement_active'));
    }

    public function decline(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->answer($this->agreements->decline($this->find($request, $uuid), $this->me($request), $data['reason'] ?? null), __('api.updated'));
    }

    /** POST projects/{uuid}/milestones/{milestone}/start | deliver {note} (FL-403) | withdraw {note?} | cancel-accept | cancel-reject (FL-506). */
    public function milestoneAction(Request $request, string $uuid, string $milestone, string $action): JsonResponse
    {
        $data = $request->validate([
            'note' => [$action === 'deliver' ? 'required' : 'nullable', 'string', 'min:5', 'max:3000'],
            'files' => ['nullable', 'array', 'max:'.\Modules\Freelance\Services\Projects\MilestoneDeliveries::MAX_FILES],
            'files.*' => ['file', 'max:'.(int) config('freelance.upload_max_kb'), 'mimes:'.\Modules\Freelance\Services\Projects\MilestoneDeliveries::MIMES, new \Modules\Freelance\Rules\CleanUpload],
        ]);
        $me = $this->me($request);
        $work = app(\Modules\Freelance\Services\Projects\MilestoneWork::class);
        $m = $work->find($this->projects->find($me, $uuid), $milestone);
        $cancel = app(\Modules\Freelance\Services\Projects\MilestoneCancellation::class);
        match ($action) {
            'start' => $work->start($m, $me),
            'withdraw' => $cancel->byFreelancer($m, $me, $data['note'] ?? null),
            'cancel-accept' => $cancel->accept($m, $me, 'freelancer'),
            'cancel-reject' => $cancel->reject($m, $me, 'freelancer'),
            default => $work->deliver($m, $me, $data['note'], array_values($request->file('files', []))),
        };

        return $this->answer($m->agreement);
    }

    /** GET earnings — what I can withdraw, what waits its days, what is on its way, and the latest ones (FL-509). */
    public function earnings(Request $request): JsonResponse
    {
        return ApiResponse::success(app(\Modules\Freelance\Services\Projects\FreelanceEarnings::class)->summary($this->me($request)), __('api.retrieved'));
    }

    /** A draft the client hasn't sent yet isn't shown to the freelancer. */
    private function find(Request $request, string $uuid): FreelanceAgreement
    {
        $me = $this->me($request);
        $a = $this->agreements->forFreelancer($this->projects->find($me, $uuid), $me);
        abort_if($a->status === FreelanceAgreement::DRAFT, 404);

        return $a;
    }

    private function answer(FreelanceAgreement $a, ?string $message = null): JsonResponse
    {
        return ApiResponse::success($this->agreements->present($a->refresh(), 'freelancer'), $message ?? __('api.retrieved'));
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
