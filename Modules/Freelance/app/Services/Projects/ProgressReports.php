<?php

namespace Modules\Freelance\Services\Projects;

use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceProgressReport as Report;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * Progress reports on an agreement (FL-605). Optional: the client may ask for one every 3, 7 or 14 days while writing the agreement
 * (`report_every_days`, null = none), and the freelancer may send one at any time while it is active. A report is a note and an
 * optional percent; it is kept as sent. A report sent starts the next period. When one is due the freelancer is reminded once, and a
 * day later - still none - the client is told it is late. Nothing is blocked or charged for a late report: it is a notice for the two.
 */
class ProgressReports
{
    /** The periods a client may ask for, in days. */
    public const EVERY_DAYS = [3, 7, 14];

    /** How long after the due date the client is told it is late. */
    public const LATE_AFTER_HOURS = 24;

    public function __construct(private readonly FreelanceNotifier $notifier) {}

    /** @return list<array<string, mixed>> newest first */
    public function list(Agreement $a): array
    {
        return Report::query()->where('agreement_id', $a->id)->orderByDesc('id')->limit(100)->get()->map(fn (Report $r) => $this->present($r))->all();
    }

    public function submit(Agreement $a, Freelancer $freelancer, string $note, ?int $percent): Report
    {
        $report = DB::transaction(function () use ($a, $freelancer, $note, $percent) {
            $locked = Agreement::query()->lockForUpdate()->findOrFail($a->id);
            if ($locked->status !== Agreement::ACTIVE || $locked->freelancer_id !== $freelancer->id) {
                throw new FreelanceException('report_not_active', 409);
            }
            $text = trim(strip_tags($note));
            if ($text === '') {
                throw new FreelanceException('report_note_required', 422);
            }
            $report = Report::query()->create(['agreement_id' => $locked->id, 'freelancer_id' => $freelancer->id, 'note' => $text, 'percent' => $percent === null ? null : max(0, min(100, $percent))]);
            $locked->fill([
                'last_report_at' => now(),
                'next_report_due_at' => $locked->report_every_days ? now()->addDays($locked->report_every_days) : null,
                'report_reminded_at' => null,
                'report_late_notified_at' => null,
            ])->save();

            return $report;
        });
        $a->refresh();
        $this->notifier->notify($a->client, 'report.received', ['project' => (string) $a->project->title, 'name' => (string) $freelancer->name], ['project' => $a->project->uuid]);

        return $report;
    }

    /** When the agreement becomes active: the first report is due after one period. */
    public function start(Agreement $a): void
    {
        if ($a->report_every_days) {
            $a->forceFill(['next_report_due_at' => now()->addDays($a->report_every_days)])->save();
        }
    }

    /** The cron: reminds the freelancer of a due report, and tells the client about one a day late. Once each per due date. */
    public function remindDue(): int
    {
        $count = 0;
        Agreement::query()->where('status', Agreement::ACTIVE)->whereNotNull('report_every_days')->whereNotNull('next_report_due_at')
            ->where('next_report_due_at', '<=', now())->with(['project', 'client', 'freelancer'])->each(function (Agreement $a) use (&$count): void {
                $project = (string) $a->project->title;
                $data = ['project' => $a->project->uuid];
                if ($a->report_reminded_at === null) {
                    $a->forceFill(['report_reminded_at' => now()])->save();
                    $this->notifier->notify($a->freelancer, 'report.due', ['project' => $project], $data);
                    $count++;
                }
                if ($a->report_late_notified_at === null && $a->next_report_due_at->copy()->addHours(self::LATE_AFTER_HOURS)->lte(now())) {
                    $a->forceFill(['report_late_notified_at' => now()])->save();
                    $this->notifier->notify($a->client, 'report.late', ['project' => $project, 'name' => (string) $a->freelancer->name], $data);
                    $count++;
                }
            });

        return $count;
    }

    /** @return array<string, mixed> what the agreement shows about its reports */
    public function summary(Agreement $a): array
    {
        return [
            'every_days' => $a->report_every_days,
            'next_due_at' => $a->next_report_due_at?->toIso8601String(),
            'last_at' => $a->last_report_at?->toIso8601String(),
            'late' => $a->status === Agreement::ACTIVE && $a->next_report_due_at !== null && $a->next_report_due_at->isPast(),
            'allowed_days' => self::EVERY_DAYS,
        ];
    }

    /** @return array<string, mixed> */
    private function present(Report $r): array
    {
        return ['uuid' => $r->uuid, 'note' => $r->note, 'percent' => $r->percent, 'created_at' => $r->created_at?->toIso8601String()];
    }
}
