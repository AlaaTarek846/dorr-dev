<?php

namespace Modules\AI\Services\World;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AI\Exceptions\AiWorldException;
use Modules\AI\Models\World\AiWorldConsent;
use Modules\AI\Models\World\AiWorldIdentityProfile;
use Modules\AI\Models\World\AiWorldProject;
use Modules\AI\Models\World\AiWorldReport;
use Modules\AI\Models\World\AiWorldSetting;
use Modules\AI\Services\AiAuditTrail;

/**
 * Reference photos are personal data. This service is the only place that stores, activates, deletes or
 * purges them: photos live on the private disk, are unusable until the owner gives explicit consent, can be
 * deleted at any time, and expire after the configured retention. Audit events never carry the photos.
 */
class AiWorldPrivacyService
{
    public const CONSENT_VERSION = '1';

    public const MAX_PHOTOS = 3;

    private const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(protected AiAuditTrail $audit) {}

    /** @return array{version: string, text: string, attestations: array<string, string>} */
    public function consentText(): array
    {
        return [
            'version' => self::CONSENT_VERSION,
            'text' => __('ai.world_consent_text'),
            'attestations' => [
                AiWorldIdentityProfile::SUBJECT_SELF => __('ai.world_attest_self'),
                AiWorldIdentityProfile::SUBJECT_OTHER => __('ai.world_attest_other'),
            ],
        ];
    }

    /** @param list<UploadedFile> $photos */
    public function createProfile(Authenticatable $owner, string $label, string $subjectType, array $photos): AiWorldIdentityProfile
    {
        if (! in_array($subjectType, [AiWorldIdentityProfile::SUBJECT_SELF, AiWorldIdentityProfile::SUBJECT_OTHER], true)) {
            throw new AiWorldException('attestation_invalid', 422);
        }

        if ($photos === [] || count($photos) > self::MAX_PHOTOS) {
            throw new AiWorldException('photos_invalid', 422);
        }

        $stored = [];

        try {
            foreach ($photos as $photo) {
                $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($photo->getRealPath());

                if (! isset(self::MIMES[$mime]) || $photo->getSize() > (int) config('ai.world.max_photo_bytes', 8 * 1024 * 1024)) {
                    throw new AiWorldException('photos_invalid', 422);
                }

                $path = 'ai-world/identity/'.$owner->getMorphClass().'-'.$owner->getAuthIdentifier().'/'.Str::uuid().'.'.self::MIMES[$mime];
                Storage::disk('local')->put($path, (string) file_get_contents($photo->getRealPath()));
                $stored[] = ['path' => $path, 'mime' => $mime];
            }
        } catch (AiWorldException $e) {
            foreach ($stored as $file) {
                Storage::disk('local')->delete($file['path']);
            }

            throw $e;
        }

        $profile = AiWorldIdentityProfile::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'label' => Str::limit($label, 120, ''),
            'subject_type' => $subjectType,
            'status' => AiWorldIdentityProfile::PENDING_CONSENT,
            'reference_files' => $stored,
            'expires_at' => now()->addDays(AiWorldSetting::current()->retention_days),
        ]);

        $this->audit->record('world_profile_created', $owner, 'info', 'ai_world_identity_profile', $profile->id, null, ['photos' => count($stored), 'subject' => $subjectType]);

        return $profile;
    }

    public function grantConsent(Authenticatable $owner, AiWorldIdentityProfile $profile, string $attestation, ?string $ip): AiWorldIdentityProfile
    {
        $expected = $profile->subject_type === AiWorldIdentityProfile::SUBJECT_SELF ? 'own_photo' : 'has_permission';

        if ($profile->status !== AiWorldIdentityProfile::PENDING_CONSENT || $attestation !== $expected) {
            throw new AiWorldException($attestation !== $expected ? 'attestation_invalid' : 'invalid_state', $attestation !== $expected ? 422 : 409);
        }

        $consent = AiWorldConsent::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'profile_id' => $profile->id,
            'text_version' => self::CONSENT_VERSION,
            'scope' => ['video_generation', 'identity_check'],
            'attestation' => $attestation,
            'granted_at' => now(),
            'ip_hash' => $ip === null ? null : hash('sha256', $ip.config('app.key')),
        ]);

        $profile->forceFill(['status' => AiWorldIdentityProfile::ACTIVE, 'consent_id' => $consent->id])->save();
        $this->audit->record('world_consent_granted', $owner, 'info', 'ai_world_identity_profile', $profile->id, null, ['text_version' => self::CONSENT_VERSION, 'attestation' => $attestation]);

        return $profile->refresh();
    }

    /** Immediate deletion: files removed, consent revoked, unfinished projects that use it are cancelled (queued scenes refunded). */
    public function deleteProfile(Authenticatable $owner, AiWorldIdentityProfile $profile): void
    {
        if ($profile->status === AiWorldIdentityProfile::DELETED) {
            return;
        }

        foreach ((array) $profile->reference_files as $file) {
            Storage::disk('local')->delete($file['path'] ?? '');
        }

        $profile->forceFill(['status' => AiWorldIdentityProfile::DELETED, 'reference_files' => null, 'deleted_at' => now()])->save();
        AiWorldConsent::query()->where('profile_id', $profile->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $projects = app(AiWorldProjectService::class);

        foreach (AiWorldProject::query()->where('identity_profile_id', $profile->id)->whereNotIn('status', [AiWorldProject::COMPLETED, AiWorldProject::FAILED, AiWorldProject::CANCELLED])->get() as $project) {
            $projects->cancel($project);
        }

        $this->audit->record('world_profile_deleted', $owner, 'info', 'ai_world_identity_profile', $profile->id);
    }

    /** Removes a project and its video files. A project that is still being generated must be cancelled first. */
    public function deleteProject(Authenticatable $owner, AiWorldProject $project): void
    {
        if (in_array($project->status, [AiWorldProject::GENERATING, AiWorldProject::STITCHING], true)) {
            throw new AiWorldException('project_busy', 409);
        }

        Storage::disk('local')->deleteDirectory('ai-world/'.$project->id);
        $project->forceFill(['final_video_path' => null])->save();
        $project->delete();
        $this->audit->record('world_project_deleted', $owner, 'info', 'ai_world_project', $project->id);
    }

    public function report(Authenticatable $reporter, ?AiWorldProject $project, string $type, ?string $notes): AiWorldReport
    {
        if (! in_array($type, AiWorldReport::TYPES, true)) {
            throw new AiWorldException('report_invalid', 422);
        }

        $report = AiWorldReport::query()->create([
            'project_id' => $project?->id,
            'reporter_type' => $reporter->getMorphClass(),
            'reporter_id' => $reporter->getAuthIdentifier(),
            'type' => $type,
            'notes' => $notes === null ? null : Str::limit($notes, 2000, ''),
        ]);

        $this->audit->record('world_reported', $reporter, 'warning', 'ai_world_report', $report->id, null, ['type' => $type]);

        return $report->refresh();
    }

    /** Daily retention: finished projects and photo sets older than the configured days are deleted. @return array{projects: int, profiles: int} */
    public function purgeExpired(): array
    {
        $days = AiWorldSetting::current()->retention_days;
        $projects = 0;
        $profiles = 0;

        foreach (AiWorldProject::query()->whereIn('status', [AiWorldProject::COMPLETED, AiWorldProject::FAILED, AiWorldProject::CANCELLED])->where('updated_at', '<', now()->subDays($days))->get() as $project) {
            Storage::disk('local')->deleteDirectory('ai-world/'.$project->id);
            $project->forceFill(['final_video_path' => null])->save();
            $project->delete();
            $projects++;
        }

        foreach (AiWorldIdentityProfile::query()->where('status', '!=', AiWorldIdentityProfile::DELETED)->where('expires_at', '<', now())->get() as $profile) {
            foreach ((array) $profile->reference_files as $file) {
                Storage::disk('local')->delete($file['path'] ?? '');
            }

            $profile->forceFill(['status' => AiWorldIdentityProfile::DELETED, 'reference_files' => null, 'deleted_at' => now()])->save();
            AiWorldConsent::query()->where('profile_id', $profile->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $profiles++;
        }

        return ['projects' => $projects, 'profiles' => $profiles];
    }
}
