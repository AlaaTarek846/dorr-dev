<?php

namespace Modules\Freelance\Services\Projects;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceDelivery;
use Modules\Freelance\Models\FreelanceDispute as Dispute;
use Modules\Freelance\Services\Chat\OfferChat;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The evidence timeline of a dispute (FL-606): everything that happened on the project in one list, oldest first, for the member of staff
 * who decides - the agreement's steps, every approval on record (FL-406), the work on the disputed part, each delivery with its files,
 * what both sides said and showed in the dispute, the dispute's own steps, and the chat between the two. Built from what is stored; it
 * adds nothing and changes nothing. The chat is read here because a dispute is open (opening the timeline is audited by the controller).
 */
class DisputeTimeline
{
    public const CHAT_LIMIT = 500;

    /** @return list<array<string, mixed>> */
    public function build(Dispute $d): array
    {
        $d->loadMissing(['agreement', 'milestone', 'project', 'evidence.media']);
        $rows = [];
        $add = function (?\DateTimeInterface $at, string $kind, string $label, ?string $actor = null, ?string $detail = null, array $files = []) use (&$rows): void {
            if ($at === null) {
                return;
            }
            $rows[] = ['at' => $at, 'kind' => $kind, 'actor' => $actor, 'label' => $label, 'detail' => $detail, 'files' => $files, 'seq' => count($rows)];
        };

        $a = $d->agreement;
        // The agreement's own steps (the versions and changes are in the approvals below).
        $add($a->created_at, 'agreement', __('freelance.timeline.agreement_created'), 'client');
        $add($a->sent_at, 'agreement', __('freelance.timeline.agreement_sent'), 'client');
        $add($a->client_approved_at, 'agreement', __('freelance.timeline.agreement_client_approved'), 'client');
        $add($a->freelancer_approved_at, 'agreement', __('freelance.timeline.agreement_freelancer_approved'), 'freelancer');
        $add($a->activated_at, 'agreement', __('freelance.timeline.agreement_active'), null);

        // Every approval on record for the project.
        foreach (app(ApprovalTrail::class)->list((int) $d->project_id, null, 'admin') as $r) {
            $when = \Illuminate\Support\Carbon::parse($r['at']);
            $add($when, 'approval', (string) $r['label'], $r['actor'], $this->payloadText((array) $r['details']));
        }

        // The work on the disputed part that isn't an approval already (it started, ...).
        DB::table('freelance_milestone_events')->where('milestone_id', $d->milestone_id)->orderBy('id')->get()->each(function ($e) use ($add): void {
            if (in_array($e->type, MilestoneWork::APPROVALS, true)) {
                return;
            }
            $key = 'freelance.timeline.work_'.str_replace('.', '_', $e->type);
            $add(\Illuminate\Support\Carbon::parse($e->created_at), 'work', Lang::has($key) ? __($key) : $e->type, $e->actor_type);
        });

        // Each delivery of the part, with its files (the staff open them through the dispute).
        FreelanceDelivery::query()->where('milestone_id', $d->milestone_id)->orderBy('number')->with('media')->get()->each(function (FreelanceDelivery $v) use ($add, $d): void {
            $add($v->created_at, 'delivery', __('freelance.timeline.delivery', ['number' => $v->number]), 'freelancer', $v->note, $v->getMedia('files')->map(fn (Media $f) => [
                'name' => MilestoneDeliveries::displayName($f), 'mime' => $f->mime_type, 'size' => $f->size, 'path' => "freelance-disputes/{$d->uuid}/deliveries/{$v->uuid}/files/{$f->id}",
            ])->values()->all());
        });

        // The dispute: how it was opened, what both sides showed, and what staff did.
        $add($d->created_at, 'dispute', __('freelance.timeline.dispute_opened', ['reason' => __('freelance.dispute_reasons.'.$d->reason)]), $d->opened_by, $d->details);
        foreach ($d->evidence as $e) {
            $f = $e->getFirstMedia('files');
            $add($e->created_at, 'evidence', __('freelance.timeline.evidence'), $e->side, $e->body, $f === null ? [] : [[
                'name' => MilestoneDeliveries::displayName($f), 'mime' => $f->mime_type, 'size' => $f->size, 'path' => "freelance-disputes/{$d->uuid}/files/{$f->id}",
            ]]);
        }
        $add($d->appealed_at, 'dispute', __('freelance.timeline.dispute_appealed'), $d->appealed_by, $d->appeal_reason);
        $add($d->decided_at, 'dispute', __('freelance.timeline.dispute_decided', ['decision' => (string) $d->decision]), 'admin', $d->decision_note);
        $add($d->resolved_at, 'dispute', __('freelance.timeline.dispute_resolved'), null);

        // The chat of the two while the dispute is open.
        $room = FreelanceConversation::query()->where('context_type', OfferChat::CONTEXT)->where('context_id', $d->project_id)->where('freelancer_id', $a->freelancer_id)->first();
        if ($room !== null) {
            $room->messages()->whereNull('deleted_at')->orderBy('id')->limit(self::CHAT_LIMIT)->get()->each(function ($m) use ($add): void {
                $add($m->created_at, 'chat', $m->kind === 'text' || $m->kind === 'system' ? (string) $m->body : '['.$m->kind.']', $m->sender_type);
            });
        }

        usort($rows, fn ($x, $y) => [$x['at']->getTimestamp(), $x['seq']] <=> [$y['at']->getTimestamp(), $y['seq']]);

        return array_map(fn ($r) => ['at' => $r['at']->format(DATE_ATOM)] + array_diff_key($r, ['at' => 1, 'seq' => 1]), $rows);
    }

    /** The short facts of an approval (a reason, a part title) as one line. @param  array<string, mixed>  $details */
    private function payloadText(array $details): ?string
    {
        $parts = [];
        foreach (['title', 'reason', 'note'] as $key) {
            if (! empty($details[$key]) && is_scalar($details[$key])) {
                $parts[] = (string) $details[$key];
            }
        }

        return $parts === [] ? null : implode(' - ', $parts);
    }
}
