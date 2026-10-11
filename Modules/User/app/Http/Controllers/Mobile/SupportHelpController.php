<?php

namespace Modules\User\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\User\Models\SupportHelpFeedback;
use Modules\User\Models\SupportHelpNode;
use Modules\User\Models\User;
use Modules\User\Services\SupportNotifier;

/**
 * The guided help menu shown before a ticket is opened: the whole active tree in the customer's language in
 * one request, so tapping through it needs no further calls. Topics that would lead nowhere (no sub-topics and
 * no answer) are left out.
 */
class SupportHelpController extends Controller
{
    public function index(): JsonResponse
    {
        $byParent = SupportHelpNode::query()->active()->with('translations')
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('parent_id');

        return ApiResponse::success([
            'greeting' => __('support.help_greeting'),
            'items' => $this->branch($byParent, null)->values(),
        ], __('api.retrieved'));
    }

    /**
     * What the customer pressed at the end of a topic: solved (`solved` true) or "I need an agent" (false).
     * Only for a topic that is on offer; it feeds the counts on the dashboard's help menu page.
     */
    public function feedback(Request $request, int $node, SupportNotifier $notifier): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');
        $solved = (bool) $request->validate(['solved' => ['required', 'boolean']])['solved'];

        $topic = SupportHelpNode::query()->active()->with('translations')->findOrFail($node);

        SupportHelpFeedback::query()->create([
            'support_help_node_id' => $topic->id,
            'user_id' => $user->id,
            'solved' => $solved,
        ]);

        // "I need an agent": the dashboard hears about it right away, before any ticket exists.
        if (! $solved) {
            $notifier->helpAskedForAgent($user, $topic->translated('title'));
        }

        return ApiResponse::success(null, __('api.created'));
    }

    /**
     * @param  Collection<int|string, Collection<int, SupportHelpNode>>  $byParent
     * @return Collection<int, array<string, mixed>>
     */
    private function branch(Collection $byParent, ?int $parentId): Collection
    {
        return ($byParent->get($parentId) ?? collect())
            ->map(function (SupportHelpNode $node) use ($byParent) {
                $children = $this->branch($byParent, $node->id)->values();
                $answer = $node->translated('answer');

                return $children->isEmpty() && blank($answer) ? null : [
                    'id' => $node->id,
                    'title' => $node->translated('title'),
                    'answer' => $answer,
                    'children' => $children,
                ];
            })
            ->filter()
            ->values();
    }
}
