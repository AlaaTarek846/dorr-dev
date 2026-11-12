<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceDispute;
use Modules\Freelance\Models\FreelanceProject;
use Modules\User\Http\Resources\AdminSupportTicketResource;
use Modules\User\Models\SupportTicket;

/**
 * Freelance on the support desk (FL-803). The tickets themselves — reading, assigning, replying, closing — stay in the support desk
 * (`support-tickets`); this adds the Freelance view of them: the freelancers' tickets and any ticket linked to a project or a dispute,
 * and the link itself. A link is a label on the ticket (`reference`); nothing else changes.
 */
class FreelanceSupportController extends Controller implements HasMiddleware
{
    public const PROJECT = 'freelance_project:';

    public const DISPUTE = 'freelance_dispute:';

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-support', [
            ['view', ['index']],
            ['update', ['link']],
        ]);
    }

    /** GET freelance-support/tickets[?status=&q=&project=uuid&dispute=uuid&per_page=] — the freelancers' tickets and the linked ones; `counts` per status. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', 'string', 'max:12'], 'q' => ['nullable', 'string', 'max:100'], 'project' => ['nullable', 'uuid'], 'dispute' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $base = SupportTicket::query()
            ->where(fn ($q) => $q->whereNotNull('freelancer_id')->orWhere('reference', 'like', 'freelance\_%'))
            ->when(! empty($f['q']), fn ($q) => $q->where(fn ($w) => $w->where('number', $f['q'])->orWhere('title', 'like', '%'.$f['q'].'%')))
            ->when(isset($f['dispute']), fn ($q) => $q->where('reference', self::DISPUTE.$f['dispute']))
            ->when(isset($f['project']), fn ($q) => $q->where(fn ($w) => $w->where('reference', self::PROJECT.$f['project'])
                ->orWhereIn('reference', FreelanceDispute::query()->whereHas('project', fn ($p) => $p->where('uuid', $f['project']))->pluck('uuid')->map(fn ($u) => self::DISPUTE.$u)->all())));
        $page = (clone $base)->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))->with(['freelancer', 'user', 'admin', 'latestMessage'])->latest('id')->paginate((int) ($f['per_page'] ?? 20));
        $counts = (clone $base)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return ApiResponse::success(
            $page->getCollection()->map(fn (SupportTicket $t) => (new AdminSupportTicketResource($t))->toArray($request) + ['link' => self::linkOf($t->reference)])->values(),
            __('api.retrieved'), 200, ApiPaginator::meta($page) + ['counts' => $counts->all()],
        );
    }

    /** POST freelance-support/tickets/{number}/link {kind: project|dispute|none, uuid?} — links a ticket to the work it is about (or clears it). */
    public function link(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['project', 'dispute', 'none'])], 'uuid' => ['required_unless:kind,none', 'nullable', 'uuid']]);
        $ticket = SupportTicket::query()->where('number', $number)->firstOrFail();
        if ($data['kind'] === 'project') {
            FreelanceProject::query()->where('uuid', $data['uuid'])->firstOrFail();
        } elseif ($data['kind'] === 'dispute') {
            FreelanceDispute::query()->where('uuid', $data['uuid'])->firstOrFail();
        }
        $before = ['reference' => $ticket->reference];
        $ticket->forceFill(['reference' => match ($data['kind']) {
            'project' => self::PROJECT.$data['uuid'],
            'dispute' => self::DISPUTE.$data['uuid'],
            default => null,
        }])->save();
        app(AuditLogger::class)->record('freelance.support.linked', $ticket, $before, ['reference' => $ticket->reference]);

        return ApiResponse::success(['number' => $ticket->number, 'link' => self::linkOf($ticket->reference)], __('api.updated'));
    }

    /** @return array{kind: string, uuid: string}|null */
    private static function linkOf(?string $reference): ?array
    {
        foreach (['project' => self::PROJECT, 'dispute' => self::DISPUTE] as $kind => $prefix) {
            if ($reference !== null && str_starts_with($reference, $prefix)) {
                return ['kind' => $kind, 'uuid' => substr($reference, strlen($prefix))];
            }
        }

        return null;
    }
}
