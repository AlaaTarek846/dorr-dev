<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use App\Services\General\AuditLogger;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\FreelanceTeamVerificationDocument as Doc;
use Modules\Freelance\Models\FreelanceTeamVerificationRequest as VRequest;
use Modules\Freelance\Models\Freelancer;

/**
 * FS-202b: verifying a freelancers' team by hand (D4) - optional, and it never stops the team from working. An Owner uploads papers (private
 * files with a title), sends them as one request, and an admin approves it (the team gets `verified_at` and the badge on its profile and offers)
 * or rejects it with a reason; an admin can also take the badge back. Every step is audited and the Owners are told.
 *
 *  - Only an Owner of an active team writes (`edit_team`); a request under review locks the papers; a verified team has nothing to send.
 *  - A rejected request keeps its papers as history; the next try starts from new ones.
 *  - Nothing about the Owners' own verification changes: each person is verified on his own (D4).
 */
class TeamVerification
{
    public const TITLE_MAX = 120;

    public const DOCS_MAX = 10;

    public const SUBMITTED = 'freelance.team.verification_submitted';

    public const DECIDED = 'freelance.team.verification_decided';

    public const REVOKED = 'freelance.team.verification_revoked';

    public const REASON_MAX = 500;

    public function __construct(
        private readonly TeamAbilities $abilities,
        private readonly NotificationCenter $notifications,
        private readonly AuditLogger $audit,
    ) {}

    // -------------------------------------------------------------- the Owner

    /**
     * `none` (nothing sent), `submitted` (waiting for an admin), `rejected`, or `verified`.
     *
     * @return array<string, mixed>
     */
    public function overview(FreelanceTeam $team): array
    {
        $last = VRequest::query()->where('team_id', $team->id)->latest('id')->first();
        $drafts = Doc::query()->where('team_id', $team->id)->whereNull('request_id')->orderBy('id')->get();
        $status = $team->verified_at !== null ? 'verified' : ($last?->status === VRequest::SUBMITTED ? 'submitted' : ($last?->status === VRequest::REJECTED ? 'rejected' : 'none'));

        return [
            'status' => $status,
            'verified_at' => $team->verified_at?->toIso8601String(),
            'documents' => $drafts->map(fn (Doc $d) => $this->presentDocument($d, $team))->values()->all(),
            'can_edit' => $team->verified_at === null && $status !== 'submitted',
            'can_submit' => $team->verified_at === null && $status !== 'submitted' && $drafts->isNotEmpty(),
            'last_request' => $last === null ? null : $this->presentRequest($last, $team),
        ];
    }

    /** A paper of the request being prepared. 403 / 409 as `assertEditable`, 409 team_verification_locked, 422 team_verification_docs_limit. */
    public function upload(FreelanceTeam $team, Freelancer $by, string $title, UploadedFile $file): Doc
    {
        $title = $this->title($title);

        return DB::transaction(function () use ($team, $by, $title, $file): Doc {
            $locked = $this->lockEditable($team, $by);
            if (Doc::query()->where('team_id', $locked->id)->whereNull('request_id')->count() >= self::DOCS_MAX) {
                throw new FreelanceException('team_verification_docs_limit', 422, ['max' => self::DOCS_MAX], ['max' => self::DOCS_MAX]);
            }
            $doc = Doc::query()->create(['team_id' => $locked->id, 'uploaded_by_freelancer_id' => $by->id, 'title' => $title]);
            $doc->setFile($file);

            return $doc;
        });
    }

    /** Only a paper not sent yet can be taken back. */
    public function deleteDraft(FreelanceTeam $team, Freelancer $by, Doc $doc): void
    {
        DB::transaction(function () use ($team, $by, $doc): void {
            $this->lockEditable($team, $by);
            if ($doc->request_id !== null) {
                throw new FreelanceException('team_verification_locked', 409);
            }
            $doc->clearMediaCollection(Doc::COLLECTION);
            $doc->delete();
        });
    }

    /** Sends the papers as one request. 422 team_verification_empty (nothing uploaded). */
    public function submit(FreelanceTeam $team, Freelancer $by): VRequest
    {
        $request = DB::transaction(function () use ($team, $by): VRequest {
            $locked = $this->lockEditable($team, $by);
            $drafts = Doc::query()->where('team_id', $locked->id)->whereNull('request_id');
            if (! (clone $drafts)->exists()) {
                throw new FreelanceException('team_verification_empty', 422);
            }
            $request = VRequest::query()->create(['team_id' => $locked->id, 'submitted_by_freelancer_id' => $by->id, 'status' => VRequest::SUBMITTED, 'submitted_at' => now()]);
            $drafts->update(['request_id' => $request->id]);

            return $request;
        });
        $this->audit->record(self::SUBMITTED, $team, null, ['request' => $request->uuid, 'by_freelancer_id' => $by->id]);

        return $request;
    }

    /** The paper of a team the person is an Owner of (404 otherwise). */
    public function documentFor(FreelanceTeam $team, string $uuid): Doc
    {
        return Doc::query()->where('team_id', $team->id)->where('uuid', $uuid)->firstOrFail();
    }

    // -------------------------------------------------------------- the admin

    /**
     * Approves or rejects the open request (a reason is needed to reject). 409 team_verification_decided (nobody waits on it any more),
     * 422 team_verification_reason_required.
     */
    public function decide(VRequest $request, string $decision, ?string $reason, int $adminId): VRequest
    {
        $reason = $this->reason($reason);
        $approve = $decision === 'approve';
        if (! $approve && $reason === null) {
            throw new FreelanceException('team_verification_reason_required', 422);
        }

        $team = DB::transaction(function () use ($request, $approve, $reason, $adminId): FreelanceTeam {
            $team = FreelanceTeam::withTrashed()->lockForUpdate()->findOrFail($request->team_id);
            $fresh = VRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($fresh->status !== VRequest::SUBMITTED) {
                throw new FreelanceException('team_verification_decided', 409);
            }
            $fresh->forceFill(['status' => $approve ? VRequest::APPROVED : VRequest::REJECTED, 'reason' => $approve ? null : $reason, 'decided_at' => now(), 'decided_by_admin_id' => $adminId])->save();
            if ($approve) {
                $team->forceFill(['verified_at' => now()])->save();
            }

            return $team;
        });
        $request->refresh();
        $this->audit->record(self::DECIDED, $team, null, ['request' => $request->uuid, 'decision' => $decision, 'reason' => $reason, 'admin' => $adminId]);
        $this->tell($team, $approve ? 'freelance.team_verified' : 'freelance.team_verification_rejected',
            $approve ? 'freelance_team_verified' : 'freelance_team_verification_rejected', ['reason' => (string) $reason]);

        return $request;
    }

    /** Takes the badge back (a reason is needed). 409 team_not_verified. */
    public function revoke(FreelanceTeam $team, string $reason, int $adminId): FreelanceTeam
    {
        $reason = $this->reason($reason) ?? throw new FreelanceException('team_verification_reason_required', 422);
        DB::transaction(function () use ($team): void {
            $locked = FreelanceTeam::withTrashed()->lockForUpdate()->findOrFail($team->id);
            if ($locked->verified_at === null) {
                throw new FreelanceException('team_not_verified', 409);
            }
            $locked->forceFill(['verified_at' => null])->save();
        });
        $team->refresh();
        $this->audit->record(self::REVOKED, $team, ['verified' => true], ['verified' => false, 'reason' => $reason, 'admin' => $adminId]);
        $this->tell($team, 'freelance.team_verification_revoked', 'freelance_team_verification_revoked', ['reason' => $reason]);

        return $team;
    }

    /**
     * What the admin sees of one team's verification: the open request first, then the earlier ones, each with its papers.
     *
     * @return array<string, mixed>
     */
    public function forAdmin(FreelanceTeam $team): array
    {
        $requests = VRequest::query()->where('team_id', $team->id)->with('documents.media')->latest('id')->limit(10)->get();

        return [
            'verified_at' => $team->verified_at?->toIso8601String(),
            'requests' => $requests->map(fn (VRequest $r) => $this->presentRequest($r, $team, true))->values()->all(),
        ];
    }

    // -------------------------------------------------------------- shape

    /** @return array<string, mixed> */
    public function presentRequest(VRequest $r, FreelanceTeam $team, bool $admin = false): array
    {
        $r->loadMissing('documents.media');

        return [
            'uuid' => $r->uuid,
            'status' => $r->status,
            'reason' => $r->reason,
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'documents' => $r->documents->sortBy('id')->map(fn (Doc $d) => $this->presentDocument($d, $team, $admin))->values()->all(),
        ];
    }

    /** @return array<string, mixed> the file is a path of a signed-in endpoint, never a link */
    public function presentDocument(Doc $d, FreelanceTeam $team, bool $admin = false): array
    {
        $m = $d->file();

        return [
            'uuid' => $d->uuid,
            'title' => $d->title,
            'file' => $m === null ? null : [
                'name' => $m->file_name, 'mime' => $m->mime_type, 'size' => $m->size,
                'path' => $admin ? "freelance-teams/{$team->uuid}/verification/documents/{$d->uuid}" : "teams/{$team->uuid}/verification/documents/{$d->uuid}",
            ],
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------- internals

    /**
     * The team locked, for an Owner of an active one with nothing under review and no badge yet: 409 freelancer_team_inactive,
     * 403 freelancer_team_ability, 409 team_already_verified, 409 team_verification_locked.
     */
    private function lockEditable(FreelanceTeam $team, Freelancer $by): FreelanceTeam
    {
        $locked = FreelanceTeam::withTrashed()->lockForUpdate()->findOrFail($team->id);
        if ($locked->trashed() || ! $locked->isActive()) {
            throw new FreelanceException('freelancer_team_inactive', 409);
        }
        $this->abilities->assert($locked, $by, TeamAbilities::EDIT_TEAM);
        if ($locked->verified_at !== null) {
            throw new FreelanceException('team_already_verified', 409);
        }
        if (VRequest::query()->where('team_id', $locked->id)->where('status', VRequest::SUBMITTED)->exists()) {
            throw new FreelanceException('team_verification_locked', 409);
        }

        return $locked;
    }

    private function title(string $title): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($title)));
        if ($text === '') {
            throw new FreelanceException('team_verification_title_required', 422);
        }

        return mb_substr($text, 0, self::TITLE_MAX);
    }

    private function reason(?string $reason): ?string
    {
        $text = $reason === null ? '' : trim(strip_tags($reason));

        return $text === '' ? null : mb_substr($text, 0, self::REASON_MAX);
    }

    private function tell(FreelanceTeam $team, string $event, string $key, array $vars): void
    {
        $owners = Freelancer::query()->whereIn('id', Member::query()->where('team_id', $team->id)->where('role', Member::OWNER)->where('status', Member::ACTIVE)->select('freelancer_id'))->get();
        $this->notifications->send($owners, $event, $key.'_title', $key.'_body', ['team' => $team->name] + $vars, ['type' => str_replace('.', '_', $event), 'team' => $team->uuid]);
    }
}
