<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\Freelancer;
use Modules\User\Enums\SupportTicketStatus;
use Modules\User\Http\Resources\SupportMessageResource;
use Modules\User\Http\Resources\SupportTicketResource;
use Modules\User\Models\SupportHelpFeedback;
use Modules\User\Models\SupportHelpNode;
use Modules\User\Models\SupportTicket;
use Modules\User\Services\SupportNotifier;
use Modules\User\Services\SupportTicketService;

/**
 * The freelancer's support - the same one the riders (`mobile/v1/support-*`) and the captains have, on the same dashboard
 * page: the guided help menu (topics for freelancers), tickets, the conversation inside each, and closing / reopening.
 * Everything is scoped to the signed-in freelancer: someone else's ticket is a 404.
 */
class FreelancerSupportController extends Controller
{
    public function __construct(private readonly SupportTicketService $service) {}

    /** The whole active help tree for freelancers in the request's language, in one request. */
    public function help(): JsonResponse
    {
        $byParent = SupportHelpNode::query()->active()->where('audience', 'freelancer')->with('translations')
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('parent_id');

        return ApiResponse::success([
            'greeting' => __('support.help_greeting'),
            'items' => $this->branch($byParent, null)->values(),
        ], __('api.retrieved'));
    }

    /** What was pressed at the end of a topic: solved, or "I need an agent" (the dashboard hears about it at once). */
    public function helpFeedback(Request $request, int $node, SupportNotifier $notifier): JsonResponse
    {
        $me = $this->me($request);
        $solved = (bool) $request->validate(['solved' => ['required', 'boolean']])['solved'];
        $topic = SupportHelpNode::query()->active()->where('audience', 'freelancer')->with('translations')->findOrFail($node);

        SupportHelpFeedback::query()->create(['support_help_node_id' => $topic->id, 'freelancer_id' => $me->id, 'solved' => $solved]);
        if (! $solved) {
            $notifier->helpAskedForAgent($me, $topic->translated('title'));
        }

        return ApiResponse::success(null, __('api.created'));
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->me($request)->supportTickets()->with('latestMessage')->latest('last_message_at')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return ApiResponse::paginated($query, SupportTicketResource::class, __('api.retrieved'));
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        return ApiResponse::success(new SupportTicketResource($this->find($request, $ticket)->load('latestMessage')), __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:4000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
        ]);
        $ticket = $this->service->open($this->me($request), $data['title'], $data['body'], $request->file('image'));

        return ApiResponse::success(new SupportTicketResource($ticket), __('api.support_ticket_created'), 201);
    }

    public function status(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([SupportTicketStatus::Closed->value, SupportTicketStatus::Reopened->value])]]);
        $moved = $this->service->customerSetStatus($this->find($request, $ticket), SupportTicketStatus::from($data['status']));

        return ApiResponse::success(new SupportTicketResource($moved->load('latestMessage')), __('api.support_ticket_status_changed'));
    }

    public function messages(Request $request, int $ticket): JsonResponse
    {
        // order=desc: the newest page first (a long conversation opens at its end; older pages follow).
        $direction = $request->query('order') === 'desc' ? 'desc' : 'asc';

        return ApiResponse::paginated($this->find($request, $ticket)->messages()->with('admin')->orderBy('id', $direction), SupportMessageResource::class, __('api.retrieved'));
    }

    public function send(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000', 'required_without:image'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
        ]);
        $message = $this->service->customerReply($this->me($request), $this->find($request, $ticket), $data['body'] ?? null, $request->file('image'));

        return $this->service->messageResponse($message, __('api.support_message_sent'));
    }

    /** The answer under an automatic FAQ reply: solved closes the ticket, otherwise the automatic replies stop and a person is asked for. */
    public function autoReplyFeedback(Request $request, int $ticket): JsonResponse
    {
        $solved = (bool) $request->validate(['solved' => ['required', 'boolean']])['solved'];
        $updated = $this->service->autoReplyFeedback($this->find($request, $ticket), $solved);

        return ApiResponse::success(new SupportTicketResource($updated->load('latestMessage')), __('api.support_ticket_status_changed'));
    }

    private function find(Request $request, int $id): SupportTicket
    {
        return $this->me($request)->supportTickets()->findOrFail($id);
    }

    /**
     * @param  Collection<int|string, Collection<int, SupportHelpNode>>  $byParent
     * @return Collection<int, array<string, mixed>>
     */
    private function branch(Collection $byParent, ?int $parentId): Collection
    {
        return ($byParent->get($parentId) ?? collect())->map(function (SupportHelpNode $node) use ($byParent) {
            $children = $this->branch($byParent, $node->id)->values();
            $answer = $node->translated('answer');

            return $children->isEmpty() && blank($answer) ? null : ['id' => $node->id, 'title' => $node->translated('title'), 'answer' => $answer, 'children' => $children];
        })->filter()->values();
    }

    private function me(Request $request): Freelancer
    {
        /** @var Freelancer */
        return $request->user('freelance_api');
    }
}
