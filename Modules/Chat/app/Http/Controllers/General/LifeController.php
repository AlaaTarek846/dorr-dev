<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatAgreement;
use Modules\Chat\Models\ChatCommitment;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatHandoff;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Services\LifeInboxService;
use Modules\Chat\Services\LifeService;
use Modules\Chat\Services\MessageService;
use Modules\Chat\Support\ParticipantType;

/**
 * Daily intelligence, batch A (spec 148, 149, 152, 154): commitments, handoffs, agreements —
 * each made from a message (or in a chat), with its card, its answers and its history.
 */
class LifeController extends Controller
{
    public function __construct(
        private readonly LifeService $life,
        private readonly MessageService $messages,
    ) {}

    // ------------------------------------------------------------------ commitments

    /** POST messages/{m}/commitment — `{title?, assignee? (participant id), due_at?, remind_minutes?}` */
    public function commitFromMessage(Request $request, ChatMessage $message)
    {
        $c = $this->life->commit($request->user(), $message->conversation, $message, $this->commitData($request));

        return ApiResponse::created($this->life->commitmentMeta($request->user(), $c), __('api.created'));
    }

    /** POST conversations/{c}/commitment — the same, without a message (a title is needed). */
    public function commit(Request $request, ChatConversation $conversation)
    {
        $c = $this->life->commit($request->user(), $conversation, null, $this->commitData($request, true));

        return ApiResponse::created($this->life->commitmentMeta($request->user(), $c), __('api.created'));
    }

    /** GET commitments?scope=mine|theirs|all&open=1&page=&per_page= — pages of 15. */
    public function commitments(Request $request)
    {
        $data = $request->validate([
            'scope' => ['nullable', Rule::in(['mine', 'theirs', 'all'])],
            'open' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $page = $this->life->commitments($request->user(), $data['scope'] ?? 'all', (bool) ($data['open'] ?? true), (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 15), $data['q'] ?? null);

        return ApiResponse::fromPaginator($page, $page->getCollection()->values(), __('api.retrieved'));
    }

    /** GET commitments/{id} — with its history. */
    public function commitment(Request $request, ChatCommitment $commitment)
    {
        $me = $request->user();
        $this->assertIn($me, $commitment->conversation);

        return ApiResponse::success($this->life->commitmentMeta($me, $commitment) + ['log' => $this->life->history('commitment', $commitment->id, $me)], __('api.retrieved'));
    }

    /** POST commitments/{id}/respond — `{accept}` (the one responsible). */
    public function respond(Request $request, ChatCommitment $commitment)
    {
        $accept = (bool) $request->validate(['accept' => ['required', 'boolean']])['accept'];

        return ApiResponse::success($this->life->commitmentMeta($request->user(), $this->life->respondCommitment($request->user(), $commitment, $accept)), __('api.updated'));
    }

    /** POST commitments/{id}/done */
    public function done(Request $request, ChatCommitment $commitment)
    {
        return ApiResponse::success($this->life->commitmentMeta($request->user(), $this->life->closeCommitment($request->user(), $commitment, 'done')), __('api.updated'));
    }

    /** POST commitments/{id}/cancel */
    public function cancel(Request $request, ChatCommitment $commitment)
    {
        return ApiResponse::success($this->life->commitmentMeta($request->user(), $this->life->closeCommitment($request->user(), $commitment, 'cancelled')), __('api.updated'));
    }

    // ------------------------------------------------------------------ handoffs

    /** POST messages/{m}/handoff — `{to_participant_id}` (a member here) or `{to_user_id}`, `note?` */
    public function handOff(Request $request, ChatMessage $message)
    {
        $data = $request->validate([
            'to_participant_id' => ['nullable', 'integer', 'required_without:to_user_id'],
            'to_user_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $account = null;
        if (empty($data['to_participant_id'])) {
            $account = ParticipantType::modelClassFor('user')::query()->find($data['to_user_id']) ?? throw ChatException::userNotFound();
        }
        $h = $this->life->handOff($request->user(), $message, isset($data['to_participant_id']) ? (int) $data['to_participant_id'] : null, $account, $data['note'] ?? null);

        return ApiResponse::created($this->life->handoffMeta($request->user(), $h), __('api.created'));
    }

    /** POST handoffs/{id}/{accept|decline|done|cancel} */
    public function moveHandoff(Request $request, ChatHandoff $handoff, string $action)
    {
        $to = ['accept' => 'accepted', 'decline' => 'declined', 'done' => 'done', 'cancel' => 'cancelled'][$action];

        return ApiResponse::success($this->life->handoffMeta($request->user(), $this->life->moveHandoff($request->user(), $handoff, $to)), __('api.updated'));
    }

    /** GET handoffs?scope=received|sent&open=1&page=&per_page= — pages of 15. */
    public function handoffs(Request $request)
    {
        $data = $request->validate([
            'scope' => ['nullable', Rule::in(['received', 'sent'])],
            'open' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $page = $this->life->handoffs($request->user(), $data['scope'] ?? 'received', (bool) ($data['open'] ?? true), (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 15), $data['q'] ?? null);

        return ApiResponse::fromPaginator($page, $page->getCollection()->values(), __('api.retrieved'));
    }

    // ------------------------------------------------------------------ agreements

    /** POST conversations/{c}/agreements — `{title, terms[{label, value}], parties?[], source? (message id)}` */
    public function propose(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'terms' => ['required', 'array', 'min:1', 'max:12'],
            'terms.*.label' => ['required', 'string', 'max:60'],
            'terms.*.value' => ['required', 'string', 'max:500'],
            'parties' => ['nullable', 'array', 'max:20'],
            'parties.*' => ['integer'],
            'source' => ['nullable', 'uuid'],
        ]);
        $me = $request->user();
        $source = ! empty($data['source']) ? $this->messages->findInConversation($conversation, $data['source']) : null;
        $a = $this->life->propose($me, $conversation, $source, $data['title'], $data['terms'], $data['parties'] ?? null);

        return ApiResponse::created($this->life->agreementMeta($me, $a), __('api.created'));
    }

    /** GET agreements/{id} — with every version's history. */
    public function agreement(Request $request, ChatAgreement $agreement)
    {
        return ApiResponse::success($this->life->agreement($request->user(), $agreement), __('api.retrieved'));
    }

    /** POST agreements/{id}/answer — `{accept, version}` */
    public function answer(Request $request, ChatAgreement $agreement)
    {
        $data = $request->validate(['accept' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);

        return ApiResponse::success($this->life->agreementMeta($request->user(), $this->life->answer($request->user(), $agreement, (bool) $data['accept'], (int) $data['version'])), __('api.updated'));
    }

    /** PUT agreements/{id} — `{title?, terms[]}`: a new version, everyone answers again. */
    public function amend(Request $request, ChatAgreement $agreement)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'terms' => ['required', 'array', 'min:1', 'max:12'],
            'terms.*.label' => ['required', 'string', 'max:60'],
            'terms.*.value' => ['required', 'string', 'max:500'],
        ]);

        return ApiResponse::success($this->life->agreementMeta($request->user(), $this->life->amend($request->user(), $agreement, $data['title'] ?? null, $data['terms'])), __('api.updated'));
    }

    /** POST agreements/{id}/cancel — the one who proposed it. */
    public function cancelAgreement(Request $request, ChatAgreement $agreement)
    {
        return ApiResponse::success($this->life->agreementMeta($request->user(), $this->life->cancelAgreement($request->user(), $agreement)), __('api.updated'));
    }

    // ------------------------------------------------------------------ the life inbox (147)

    /** GET life-inbox?timezone=&section=&page=&per_page=&q= — pages of 15 for one bucket. */
    public function inbox(Request $request)
    {
        $data = $request->validate([
            'timezone' => ['nullable', 'timezone:all'],
            'section' => ['nullable', Rule::in(['reply', 'pay', 'approve', 'follow', 'appointments'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $zone = $data['timezone'] ?? ($request->user()->timezone ?? config('app.timezone', 'UTC'));
        $page = app(LifeInboxService::class)->inbox(
            $request->user(),
            $zone,
            $data['section'] ?? null,
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 15),
            $data['q'] ?? null,
        );

        return ApiResponse::fromPaginator($page['paginator'], $page['data'], __('api.retrieved'));
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function commitData(Request $request, bool $titleRequired = false): array
    {
        return $request->validate([
            'title' => [$titleRequired ? 'required' : 'nullable', 'string', 'max:300'],
            'assignee' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date', 'after:now'],
            'remind_minutes' => ['nullable', 'integer', Rule::in([0, 15, 30, 60, 120, 1440])],
        ]);
    }

    private function assertIn($me, ChatConversation $conversation): void
    {
        app(ConversationService::class)->participantOf($me, $conversation);
    }
}
