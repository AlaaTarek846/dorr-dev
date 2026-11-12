<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Crm\FreelancerCrm;

/**
 * FS-703, the freelancer's private CRM (what a plan's `crm` opens — see FreelancerCrm):
 *  - `GET crm/clients?tab=all|active|past|leads|starred&search=&page=&per_page=` — the clients, the count of every tab and the follow-ups due (`locked: true` + the plan that opens it when the plan has no CRM).
 *  - `GET crm/clients/{uuid}` — one client with notes, reminders, projects together and what is still waiting.
 *  - `PUT crm/clients/{uuid}` {starred?, label?} — the star (CRM 1) and the label (CRM 2).
 *  - `POST|PUT|DELETE crm/clients/{uuid}/notes[/{note}]` {body} — private notes (CRM 1).
 *  - `POST crm/clients/{uuid}/reminders` {title, due_at}, `PUT …/reminders/{reminder}` {done}, `DELETE …` — follow-ups (CRM 2).
 *  - `GET crm/reminders` — every follow-up still waiting, soonest first (CRM 2).
 * Every answer of a change is the client as `show` gives it, so the app repaints from one object.
 */
class FreelancerCrmController extends Controller
{
    public function __construct(private readonly FreelancerCrm $crm) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'tab' => ['nullable', Rule::in(FreelancerCrm::TABS)], 'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return ApiResponse::success($this->crm->overview($this->me($request), $f), __('api.retrieved'));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->crm->show($this->me($request), $uuid), __('api.retrieved'));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'starred' => ['sometimes', 'boolean'],
            'label' => ['sometimes', 'nullable', Rule::in((array) config('freelance.crm.labels', []))],
        ]);

        return ApiResponse::success($this->crm->update($this->me($request), $uuid, $data), __('api.updated'));
    }

    public function storeNote(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:1000']]);

        return ApiResponse::created($this->crm->addNote($this->me($request), $uuid, $data['body']), __('freelance.crm_saved'));
    }

    public function updateNote(Request $request, string $uuid, string $note): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:1000']]);

        return ApiResponse::success($this->crm->editNote($this->me($request), $uuid, $note, $data['body']), __('freelance.crm_saved'));
    }

    public function destroyNote(Request $request, string $uuid, string $note): JsonResponse
    {
        return ApiResponse::success($this->crm->deleteNote($this->me($request), $uuid, $note), __('freelance.crm_deleted'));
    }

    public function storeReminder(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'min:1', 'max:120'], 'due_at' => ['required', 'date']]);

        return ApiResponse::created($this->crm->addReminder($this->me($request), $uuid, $data['title'],
            // The app sends the moment with its own offset (+02:00); Eloquent would store the clock time as it is written, so bring it to the server's zone first.
            Carbon::parse($data['due_at'])->setTimezone((string) config('app.timezone', 'UTC'))), __('freelance.crm_saved'));
    }

    public function updateReminder(Request $request, string $uuid, string $reminder): JsonResponse
    {
        $data = $request->validate(['done' => ['required', 'boolean']]);

        return ApiResponse::success($this->crm->finishReminder($this->me($request), $uuid, $reminder, (bool) $data['done']), __('freelance.crm_saved'));
    }

    public function destroyReminder(Request $request, string $uuid, string $reminder): JsonResponse
    {
        return ApiResponse::success($this->crm->deleteReminder($this->me($request), $uuid, $reminder), __('freelance.crm_deleted'));
    }

    public function reminders(Request $request): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success(['reminders' => $this->crm->upcoming($me)], __('api.retrieved'));
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
