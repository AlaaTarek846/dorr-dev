<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceDispute as Dispute;
use Modules\Freelance\Services\Chat\OfferChat;
use Modules\Freelance\Services\Projects\DisputeTimeline;
use Modules\Freelance\Services\Projects\Disputes;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The disputes desk (FL-705): the cases (with the ones whose response time is up first), one case with everything both sides said and
 * showed — the part, its delivery and history, the chat — and the decision. A first decision waits for the appeal days; after an
 * appeal another member of staff decides, finally.
 */
class FreelanceDisputeController extends Controller implements HasMiddleware
{
    public function __construct(private readonly Disputes $disputes, private readonly DisputeTimeline $timeline) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-disputes', [
            ['view', ['index', 'show', 'file', 'timeline', 'exportTimeline', 'deliveryFile']],
            ['update', ['decide']],
        ]);
    }

    /** GET freelance-disputes[?status=&country_id=&per_page=] — `counts` per status. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([Dispute::OPEN, Dispute::DECIDED, Dispute::APPEALED, Dispute::RESOLVED])],
            'country_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $base = Dispute::query()->when(isset($f['country_id']), fn ($q) => $q->whereHas('project', fn ($p) => $p->where('country_id', $f['country_id'])));
        $page = (clone $base)->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->with(['project', 'milestone', 'agreement'])
            // What needs a decision first: open and appealed, the oldest at the top.
            ->orderByRaw("case status when 'appealed' then 0 when 'open' then 1 when 'decided' then 2 else 3 end")->orderBy('id')
            ->paginate((int) ($f['per_page'] ?? 20));
        $counts = (clone $base)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return ApiResponse::success($page->getCollection()->map(fn (Dispute $d) => [
            'uuid' => $d->uuid, 'status' => $d->status, 'reason' => $d->reason, 'reason_label' => __('freelance.dispute_reasons.'.$d->reason), 'opened_by' => $d->opened_by,
            'project' => ['uuid' => $d->project?->uuid, 'title' => $d->project?->title],
            'milestone' => ['title' => $d->milestone?->title, 'amount_minor' => $d->milestone?->amount_minor],
            'currency' => $d->agreement?->currency_code,
            'respond_by' => $d->respond_by?->toIso8601String(),
            'overdue' => $d->status === Dispute::OPEN && $d->respond_by?->isPast() === true,
            'opened_at' => $d->created_at?->toIso8601String(),
        ])->values(), __('api.retrieved'), 200, \App\Support\Api\ApiPaginator::meta($page) + ['counts' => $counts->map(fn ($n) => (int) $n)->all()]);
    }

    /** GET freelance-disputes/{uuid} — the case: the dispute, the part and its history, the agreement, the chat. */
    public function show(string $uuid): JsonResponse
    {
        $d = Dispute::query()->where('uuid', $uuid)->with(['agreement.client', 'agreement.freelancer', 'milestone', 'evidence.media', 'project'])->firstOrFail();
        $m = $d->milestone;
        $a = $d->agreement;
        $room = FreelanceConversation::query()->where('context_type', OfferChat::CONTEXT)->where('context_id', $d->project_id)->where('freelancer_id', $a->freelancer_id)->first();

        return ApiResponse::success($this->disputes->present($d, 'admin', "freelance-disputes/{$uuid}/files") + [
            'project' => ['uuid' => $d->project->uuid, 'title' => $d->project->title, 'status' => $d->project->status->value],
            'client' => ['id' => $a->client?->id, 'name' => $a->client?->name],
            'freelancer' => ['id' => $a->freelancer?->id, 'name' => $a->freelancer?->name],
            'agreement' => ['uuid' => $a->uuid, 'scope' => $a->scope, 'includes' => $a->includes, 'excludes' => $a->excludes, 'price_minor' => $a->price_minor, 'duration_days' => $a->duration_days],
            'part' => [
                'delivery_note' => $m->delivery_note, 'revision_reason' => $m->revision_reason, 'revisions_used' => $m->revisions_used,
                'net_minor' => $m->net_minor, 'commission_minor' => $m->commission_minor, 'held_minor' => app(\Modules\Freelance\Services\Projects\MilestonePayments::class)->held($m),
                'history' => app(\Modules\Freelance\Services\Projects\MilestoneWork::class)->history($m),
            ],
            'chat' => $room === null ? [] : $room->messages()->orderBy('id')->limit(300)->get()
                ->map(fn ($msg) => ['from' => $msg->sender_type, 'body' => $msg->deleted_at ? null : $msg->body, 'kind' => $msg->kind, 'at' => $msg->created_at?->toIso8601String()])->all(),
            'decided_by_admin_id' => $d->decided_by_admin_id,
            // FL-406: everything both sides agreed to on this project.
            'trail' => app(\Modules\Freelance\Services\Projects\ApprovalTrail::class)->list((int) $d->project_id, [(int) $d->agreement_id], 'admin'),
        ], __('api.retrieved'));
    }

    /** POST freelance-disputes/{uuid}/decide {decision: refund|release|split, freelancer_percent?, note} */
    public function decide(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(Dispute::DECISIONS)],
            'freelancer_percent' => ['required_if:decision,split', 'nullable', 'numeric', 'gt:0', 'lt:100'],
            'note' => ['required', 'string', 'min:10', 'max:3000'],
        ]);
        $d = Dispute::query()->where('uuid', $uuid)->firstOrFail();
        $bp = isset($data['freelancer_percent']) ? (int) round(((float) $data['freelancer_percent']) * 100) : null;
        $this->disputes->decide($d, (int) $request->user('admin_api')->getKey(), $data['decision'], $bp, $data['note']);

        return $this->show($uuid);
    }

    /**
     * GET freelance-disputes/{uuid}/timeline (FL-606) - everything on the project in one list, oldest first. Reading it (the chat is in
     * it) is written to the audit log with who did.
     */
    public function timeline(string $uuid): JsonResponse
    {
        $d = Dispute::query()->where('uuid', $uuid)->firstOrFail();
        $entries = $this->timeline->build($d);
        app(\App\Services\General\AuditLogger::class)->record('freelance.dispute.timeline_viewed', $d, null, ['entries' => count($entries)]);

        return ApiResponse::success(['dispute' => $uuid, 'entries' => $entries], __('api.retrieved'));
    }

    /** GET freelance-disputes/{uuid}/timeline/export - the same list as a CSV file for the case folder. */
    public function exportTimeline(string $uuid): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $d = Dispute::query()->where('uuid', $uuid)->firstOrFail();
        $entries = $this->timeline->build($d);
        app(\App\Services\General\AuditLogger::class)->record('freelance.dispute.timeline_exported', $d, null, ['entries' => count($entries)]);

        return response()->streamDownload(function () use ($entries): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['at', 'kind', 'who', 'what', 'details', 'files']);
            foreach ($entries as $e) {
                // A cell that starts like a formula is written as text, so opening the file in a spreadsheet can't run it.
                $safe = fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v;
                fputcsv($out, [$e['at'], $e['kind'], $e['actor'], $safe($e['label']), $safe($e['detail']), implode('; ', array_column($e['files'], 'name'))]);
            }
            fclose($out);
        }, "dispute-{$d->uuid}-timeline.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** GET freelance-disputes/{uuid}/deliveries/{delivery}/files/{media} - a delivered file of the disputed part. */
    public function deliveryFile(string $uuid, string $delivery, int $media): BinaryFileResponse
    {
        $d = Dispute::query()->where('uuid', $uuid)->firstOrFail();
        $v = \Modules\Freelance\Models\FreelanceDelivery::query()->where('milestone_id', $d->milestone_id)->where('uuid', $delivery)->firstOrFail();
        $file = $v->getMedia('files')->firstWhere('id', $media) ?? abort(404);

        return tap(response()->download($file->getPath(), \Modules\Freelance\Services\Projects\MilestoneDeliveries::displayName($file), ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff'], 'attachment'), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    /** GET freelance-disputes/{uuid}/files/{media} */
    public function file(string $uuid, int $media): BinaryFileResponse
    {
        $file = $this->disputes->file(Dispute::query()->where('uuid', $uuid)->firstOrFail(), $media);

        return tap(response()->download($file->getPath(), $file->file_name, ['Content-Type' => $file->mime_type]), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }
}
