<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Driver\Models\Driver;
use Modules\User\Enums\SupportTicketStatus;
use Modules\User\Http\Resources\SupportMessageResource;
use Modules\User\Http\Resources\SupportTicketResource;
use Modules\User\Models\SupportHelpNode;
use Modules\User\Models\SupportSetting;
use Modules\User\Models\SupportTicket;
use Modules\User\Services\SupportTicketService;

/**
 * The captain's support (docs/rides_tasks.md D-285..D-293, D-214, D-298): whether the team is there now and its
 * hours, the help centre (articles for captains, searchable), tickets with a category, the ride they're about and a
 * photo / document, the conversation inside each, and closing / reopening — the same support the riders have, on
 * the same dashboard.
 */
class DriverSupportController extends Controller
{
    /** D-289: what a captain's ticket can be about. */
    public const CATEGORIES = ['trip', 'payment', 'earnings', 'withdrawal', 'documents', 'account', 'app', 'safety', 'lost_item', 'unauthorized_transaction', 'other'];

    public function __construct(private readonly SupportTicketService $service) {}

    /** D-285, D-286: the team's hours and the help centre for captains. */
    public function help(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $nodes = SupportHelpNode::query()->active()->whereIn('audience', ['all', 'driver'])->with('translations')->orderBy('sort_order')->orderBy('id')->get();
        $settings = SupportSetting::current();
        $locale = app()->getLocale();

        return ApiResponse::success([
            'open_now' => ! $settings->hasHours() || $settings->isOpenAt(now()),
            'hours' => $settings->hasHours() ? $settings->hoursText($locale) : null,
            'categories' => array_map(fn (string $c) => ['key' => $c, 'label' => __('rides.support.categories.'.$c)], self::CATEGORIES),
            // D-287: a search through every article's title and answer.
            'results' => $q === '' ? [] : $nodes->filter(fn (SupportHelpNode $n) => filled($n->translated('answer'))
                && (mb_stripos((string) $n->translated('title'), $q) !== false || mb_stripos((string) $n->translated('answer'), $q) !== false))
                ->map(fn (SupportHelpNode $n) => ['id' => $n->id, 'title' => $n->translated('title'), 'answer' => $n->translated('answer'), 'children' => []])->values(),
            'items' => $this->branch($nodes->groupBy('parent_id'), null)->values(),
        ], __('api.retrieved'));
    }

    public function index(Request $request): JsonResponse
    {
        $rows = SupportTicket::query()->where('driver_id', $this->driver($request)->id)->with('latestMessage')->latest('last_message_at')->latest('id')->limit(100)->get();

        return ApiResponse::success(SupportTicketResource::collection($rows)->resolve() , __('api.retrieved'));
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        return ApiResponse::success(new SupportTicketResource($this->find($request, $ticket)->load('latestMessage')), __('api.retrieved'));
    }

    /** D-288..D-291, D-214, D-298: a ticket — its category, the ride (one of mine) or the transaction it's about, a file. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::in(self::CATEGORIES)], 'ride_uuid' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:64'],
            'image' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);
        $driver = $this->driver($request);
        $rideId = empty($data['ride_uuid']) ? null : DB::table('rides')->where('uuid', $data['ride_uuid'])->where('driver_id', $driver->id)->value('id');
        $ticket = $this->service->open($driver, $data['title'], $data['body'], $request->file('image'), $rideId, $data['category'], $data['reference'] ?? null);

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
        $rows = $this->find($request, $ticket)->messages()->with('admin')->orderBy('id')->limit(500)->get();

        return ApiResponse::success(SupportMessageResource::collection($rows)->resolve(), __('api.retrieved'));
    }

    public function send(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate(['body' => ['nullable', 'string', 'max:5000', 'required_without:image'], 'image' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf']]);
        $message = $this->service->customerReply($this->driver($request), $this->find($request, $ticket), $data['body'] ?? null, $request->file('image'));

        return $this->service->messageResponse($message, __('api.support_message_sent'));
    }

    private function find(Request $request, int $id): SupportTicket
    {
        return SupportTicket::query()->where('driver_id', $this->driver($request)->id)->findOrFail($id);
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

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
