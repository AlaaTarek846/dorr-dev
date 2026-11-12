<?php

namespace Modules\Freelance\Services\Chat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceMessage;
use Modules\Freelance\Models\FreelanceMessageReport as Report;

/**
 * Reporting an abusive message of a room (FL-709). Either person of the room may report a message the OTHER one sent (never their own,
 * never a system line), once, with a reason and a few words. The text is copied when reported, so it is still there for staff if the
 * sender takes the message back. The other person is not told. Staff decide: "dismissed" or "upheld" (the message may be hidden from
 * the room). A report is information for a person to look at - it never restricts an account by itself (warnings and limits are
 * FL-708, and always a human's decision).
 */
class MessageReports
{
    public const REASONS = ['abuse', 'harassment', 'blackmail', 'contact_info', 'spam', 'other'];

    public function __construct(private readonly FreelanceChatService $chat) {}

    /** @throws ModelNotFoundException a message of a room that isn't yours looks like it doesn't exist */
    public function report(Model $me, int $messageId, string $reason, ?string $note): Report
    {
        $side = FreelanceChatService::side($me);
        $message = FreelanceMessage::query()->with('conversation')->findOrFail($messageId);
        $conversation = $message->conversation;
        if ((int) $conversation->{$side === 'client' ? 'client_id' : 'freelancer_id'} !== (int) $me->getKey()) {
            throw new ModelNotFoundException;
        }
        if ($message->sender_type === 'system' || $message->sender_type === $side) {
            throw new FreelanceException('report_not_allowed', 422);
        }
        if ($message->deleted_at !== null) {
            throw new FreelanceException('report_message_gone', 422);
        }
        $note = $note === null ? null : (mb_substr(trim(strip_tags($note)), 0, 500) ?: null);
        $data = [
            'message_id' => $message->id, 'conversation_id' => $conversation->id, 'reporter_type' => $side, 'reporter_id' => $me->getKey(),
            'reason' => in_array($reason, self::REASONS, true) ? $reason : 'other', 'note' => $note, 'message_body' => $message->body,
        ];
        if (Report::query()->where('message_id', $message->id)->where('reporter_type', $side)->where('reporter_id', $me->getKey())->exists()) {
            throw new FreelanceException('report_already', 409);
        }
        try {
            $report = Report::query()->create($data);
            // The file is copied too: hiding the message (or the sender taking it back) removes the original, the evidence stays.
            $message->getSingleMedia('attachment')?->copy($report, 'evidence');

            return $report;
        } catch (QueryException) {
            // Two taps racing: the unique key let only one in.
            throw new FreelanceException('report_already', 409);
        }
    }

    /** Staff decide a report once. `$hide` takes the message out of the room (upheld only). */
    public function decide(Report $report, Admin $admin, string $decision, ?string $note, bool $hide): Report
    {
        return DB::transaction(function () use ($report, $admin, $decision, $note, $hide) {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
            if ($locked->status !== Report::OPEN) {
                throw new FreelanceException('report_already_decided', 409);
            }
            $upheld = $decision === 'uphold';
            $hidden = false;
            if ($upheld && $hide) {
                $message = FreelanceMessage::query()->find($locked->message_id);
                if ($message !== null && $message->deleted_at === null) {
                    $this->chat->hide($message);
                    $hidden = true;
                }
            }
            $locked->forceFill([
                'status' => $upheld ? Report::UPHELD : Report::DISMISSED, 'decided_by' => $admin->id, 'decided_at' => now(),
                'decision_note' => $note === null ? null : (mb_substr(trim(strip_tags($note)), 0, 500) ?: null), 'message_hidden' => $hidden,
            ])->save();

            return $locked;
        });
    }

    /** @return array<string, mixed> for staff */
    public function present(Report $r): array
    {
        $message = $r->message;
        $room = $r->conversation;
        $file = $r->getSingleMedia('evidence');

        return [
            'uuid' => $r->uuid, 'status' => $r->status, 'reason' => $r->reason, 'note' => $r->note,
            'reporter' => ['type' => $r->reporter_type, 'name' => $r->reporter_type === 'client' ? $room?->client?->name : $room?->freelancer?->name],
            'reported' => ['type' => $r->reporter_type === 'client' ? 'freelancer' : 'client', 'name' => $r->reporter_type === 'client' ? $room?->freelancer?->name : $room?->client?->name],
            'message' => ['id' => $r->message_id, 'body' => $r->message_body, 'kind' => $message?->kind, 'now_hidden' => $message?->deleted_at !== null],
            'file' => $file === null ? null : [
                'name' => \Modules\Freelance\Services\Projects\MilestoneDeliveries::displayName($file), 'mime' => $file->mime_type, 'size' => $file->size,
                'is_image' => str_starts_with((string) $file->mime_type, 'image/'), 'path' => "freelance-message-reports/{$r->uuid}/file",
            ],
            'conversation' => $room?->uuid,
            'decision_note' => $r->decision_note, 'message_hidden' => $r->message_hidden,
            'decided_at' => $r->decided_at?->toIso8601String(), 'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
