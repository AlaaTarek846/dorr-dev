<?php

namespace Modules\Moving\Services\Notifications;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\DB;
use Modules\Moving\Events\MovingRequestChanged;
use Modules\Moving\Events\MovingStatusBroadcast;
use Modules\Moving\Models\MovingRequest;
use Modules\Provider\Models\Branch;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Provider;
use Modules\Provider\Services\Establishments\EstablishmentAccess;

/**
 * Who hears about each step of a move (B-16, REQ-111): every change reloads the open screens once (live channel
 * `moving.{uuid}`), and the important ones are also stored in-app and pushed, opening the right screen (`type` +
 * `request`). Providers hear only what their permissions cover: a new request goes to the people of the reached
 * branches who may see requests; a job's steps to whoever follows jobs in its branch; the driver about their job.
 *
 * Texts: lang/{ar,en}/notifications.php → `moving_{audience}_{action}_title|body`.
 */
class MovingNotifier
{
    /** action => who is told (customer, branch = may see requests, jobs = may follow jobs, driver) */
    public const ACTIONS = [
        'published' => ['branch'],
        'offer_received' => ['customer'],
        'accepted' => ['jobs'],
        'assigned' => ['customer', 'driver'],
        'vehicle_replaced' => ['customer'],
        'driver_declined' => ['jobs'],
        'driver_left' => ['jobs'],
        'set_out' => ['customer'],
        'arrived' => ['customer'],
        'delivered' => ['customer', 'jobs'],
        'settled' => ['jobs'],
        'expired' => ['customer'],
        'cancelled' => ['customer', 'jobs', 'driver'],
        'reported' => ['jobs'],
        'exception_requested' => ['customer', 'jobs'],
        'report_decided' => ['customer', 'jobs'],
        'change_requested' => ['customer', 'jobs'],
        'change_applied' => ['customer', 'jobs'],
        'change_rejected' => ['customer', 'jobs'],
        'ticket_opened' => ['jobs'],
    ];

    public function __construct(
        private readonly NotificationCenter $notifications,
        private readonly EstablishmentAccess $access,
    ) {}

    public function handle(MovingRequestChanged $event): void
    {
        $request = $event->request;
        event(new MovingStatusBroadcast($request->uuid, $request->status->value, $event->action));

        foreach (self::ACTIONS[$event->action] ?? [] as $audience) {
            $receivers = match ($audience) {
                'customer' => $request->user ? [$request->user] : [],
                'branch' => $this->branchPeople($request),
                'jobs' => $this->jobPeople($request),
                'driver' => $request->driver?->provider ? [$request->driver->provider] : [],
            };
            if ($receivers === []) {
                continue;
            }
            $audienceKey = $audience === 'customer' ? 'customer' : 'provider';
            $lang = "moving_{$audienceKey}_{$event->action}";
            $this->notifications->send($receivers, 'moving.'.$event->action, $lang.'_title', $lang.'_body', [
                'area' => (string) $request->pickup_area,
            ], ['type' => 'moving_'.$event->action, 'request' => $request->uuid]);
        }
    }

    /** @return list<Provider> people of the reached branches allowed to see requests */
    private function branchPeople(MovingRequest $request): array
    {
        $branchIds = DB::table('moving_request_branches')->where('request_id', $request->id)->pluck('branch_id');
        $branches = Branch::query()->whereIn('id', $branchIds)->get();

        return $this->people($branches->pluck('establishment_id')->unique()->all(), fn (Membership $m) => $branches
            ->where('establishment_id', $m->establishment_id)->contains(fn ($b) => $this->access->can($m, 'requests.view', $b)));
    }

    /** @return list<Provider> people of the job's establishment who follow its branch's jobs */
    private function jobPeople(MovingRequest $request): array
    {
        if ($request->establishment_id === null) {
            return [];
        }
        $branch = $request->branch;

        return $this->people([$request->establishment_id], fn (Membership $m) => $this->access->can($m, 'jobs.track', $branch));
    }

    /**
     * @param  list<int>  $establishmentIds
     * @return list<Provider>
     */
    private function people(array $establishmentIds, callable $allowed): array
    {
        return Membership::query()->with('provider')->whereIn('establishment_id', $establishmentIds)->where('status', 'active')->get()
            ->filter($allowed)->pluck('provider')->filter()->unique('id')->values()->all();
    }
}
