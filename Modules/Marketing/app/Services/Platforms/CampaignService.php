<?php

namespace Modules\Marketing\Services\Platforms;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Marketing\Events\MarketingChanged;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Models\MarketingApproval;
use Modules\Marketing\Models\MarketingCampaign;
use Modules\Marketing\Models\MarketingCampaignAction;
use Modules\Marketing\Models\MarketingContentItem;
use Modules\Marketing\Models\MarketingIntegration;
use Modules\Marketing\Models\MarketingMetric;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingProjectMember;
use Modules\Marketing\Services\Budgets\BudgetService;
use Modules\Marketing\Services\Content\ClaimsReviewer;
use Modules\Marketing\Services\Features\MarketingCountry;
use Modules\Marketing\Services\Features\MarketingFeatures;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\MarketingAudit;
use Modules\Marketing\Services\Optimisation\AlertRuleService;
use Throwable;

/**
 * Campaigns (U09, MK-C02..C05). The AI never publishes or spends: a person drafts, the server validates, the owner (or
 * a manager holding a delegation) approves one exact version with a money cap, and publishing needs that approval,
 * the same version, the same payload hash, the expected budget and an Idempotency-Key (POL-01/02/07). A publish
 * whose answer never came is UNKNOWN until a reconcile finds the campaign by its marker (AT-06).
 */
class CampaignService
{
    public const MAX_DAYS = 90;

    public const MIN_DAILY_MINOR = 100;

    public const APPROVAL_DAYS = 7;

    public function __construct(
        private readonly PlatformRegistry $platforms,
        private readonly MarketingAccess $access,
        private readonly MarketingAudit $audit,
        private readonly DelegationService $delegations,
        private readonly IntegrationService $integrations,
        private readonly BudgetService $budgets,
        private readonly MarketingFeatures $features,
        private readonly NotificationCenter $notifications,
    ) {}

    /** @param  array<string, mixed>  $data  validated */
    public function create(MarketingProject $project, Model $actor, array $data): MarketingCampaign
    {
        $this->access->assert($project, $actor, 'edit');
        $integration = $this->integration($project, $data['integration'] ?? null);
        $campaign = new MarketingCampaign([
            'project_id' => $project->id,
            'provider' => $integration?->provider ?? $data['provider'] ?? 'meta',
            'status' => MarketingCampaign::DRAFT,
            'version' => 1,
            'created_by_type' => $actor->getMorphClass(),
            'created_by_id' => $actor->getKey(),
        ]);
        $this->fill($campaign, $project, $data, $integration);
        $campaign->save();
        $this->audit->record($project, $actor, 'campaign.created', $campaign, after: $campaign->payload());
        $this->changed($campaign);

        return $campaign;
    }

    /** @param  array<string, mixed>  $data  validated, with `version` */
    public function update(MarketingCampaign $campaign, Model $actor, array $data): MarketingCampaign
    {
        $this->access->assert($campaign->project, $actor, 'edit');

        return DB::transaction(function () use ($campaign, $actor, $data) {
            $campaign = MarketingCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if (in_array($campaign->status, [MarketingCampaign::SUBMITTING, MarketingCampaign::PUBLISHED, MarketingCampaign::UNKNOWN], true)) {
                throw new MarketingException('invalid_transition', 409, [], ['status' => $campaign->status]);
            }
            if ((int) $data['version'] !== $campaign->version) {
                throw new MarketingException('version_conflict', 409, [], ['version' => $campaign->version]);
            }
            $before = $campaign->payload();
            $integration = array_key_exists('integration', $data) ? $this->integration($campaign->project, $data['integration']) : $campaign->integration;
            $this->fill($campaign, $campaign->project, $data + [
                'name' => $campaign->name, 'objective' => $campaign->objective, 'daily_budget_minor' => $campaign->daily_budget_minor,
                'start_at' => $campaign->start_at, 'end_at' => $campaign->end_at, 'targeting' => $campaign->targeting,
            ], $integration);
            // POL-02: any change is a new version; the approval of the old one stops counting.
            $campaign->fill(['version' => $campaign->version + 1, 'status' => MarketingCampaign::DRAFT, 'payload_hash' => null, 'validation' => null]);
            $campaign->save();
            $superseded = $this->supersede($campaign);
            $this->audit->record($campaign->project, $actor, 'campaign.updated', $campaign, before: $before, after: $campaign->payload(), meta: ['version' => $campaign->version, 'approvals_superseded' => $superseded]);
            $this->changed($campaign);

            return $campaign;
        });
    }

    /** Checks everything that can be checked before asking for money (U09 "validate"). */
    public function validate(MarketingCampaign $campaign, Model $actor): MarketingCampaign
    {
        $this->access->assert($campaign->project, $actor, 'edit');
        if (! in_array($campaign->status, [MarketingCampaign::DRAFT, MarketingCampaign::VALIDATED, MarketingCampaign::REJECTED, MarketingCampaign::FAILED], true)) {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $campaign->status]);
        }
        $issues = $this->issues($campaign);
        if ($issues !== []) {
            $campaign->update(['status' => MarketingCampaign::DRAFT, 'validation' => $issues, 'payload_hash' => null]);
            $this->audit->record($campaign->project, $actor, 'campaign.validation_failed', $campaign, meta: ['issues' => array_column($issues, 'code')]);

            throw new MarketingException('campaign_invalid', 422, [], ['issues' => $issues]);
        }
        $campaign->update(['status' => MarketingCampaign::VALIDATED, 'validation' => [], 'payload_hash' => MarketingAudit::hash($campaign->payload()), 'last_error' => null]);
        $this->audit->record($campaign->project, $actor, 'campaign.validated', $campaign, after: ['hash' => $campaign->payload_hash]);
        $this->changed($campaign);

        return $campaign;
    }

    public function requestApproval(MarketingCampaign $campaign, Model $actor): MarketingCampaign
    {
        $this->access->assert($campaign->project, $actor, 'edit');
        $this->assertStatus($campaign, [MarketingCampaign::VALIDATED]);
        $campaign->update(['status' => MarketingCampaign::AWAITING_APPROVAL]);
        $this->audit->record($campaign->project, $actor, 'campaign.approval_requested', $campaign);
        $this->notifyApprovers($campaign);
        $this->changed($campaign);

        return $campaign;
    }

    /** The owner, or a manager with a valid delegation that still has room for the whole campaign budget. */
    public function approve(MarketingCampaign $campaign, Model $actor, int $version): MarketingApproval
    {
        $role = $this->access->assert($campaign->project, $actor, 'read');

        return DB::transaction(function () use ($campaign, $actor, $version, $role) {
            $campaign = MarketingCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $delegation = null;
            if (! $role->isOwner()) {
                $delegation = $this->delegations->activeFor($campaign->project, $actor);
                if (! $this->delegations->valid($delegation) || $delegation->currency !== $campaign->currency) {
                    $this->audit->record($campaign->project, $actor, 'access.denied', $campaign, meta: ['ability' => 'approve_spend']);

                    throw new MarketingException('spend_approval_forbidden', 403);
                }
                $room = (int) $delegation->cap_minor - $this->delegations->used($delegation, $campaign->id);
                if ($campaign->total_budget_minor > $room) {
                    throw new MarketingException('delegation_cap_exceeded', 403, [], ['cap_minor' => $delegation->cap_minor, 'room_minor' => max(0, $room)]);
                }
            }
            $this->assertStatus($campaign, [MarketingCampaign::AWAITING_APPROVAL]);
            if ($version !== $campaign->version || $campaign->payload_hash !== MarketingAudit::hash($campaign->payload())) {
                throw new MarketingException('version_conflict', 409, [], ['version' => $campaign->version]);
            }
            $this->supersede($campaign);
            $approval = MarketingApproval::query()->create([
                'project_id' => $campaign->project_id,
                'subject_type' => 'campaign',
                'subject_id' => $campaign->id,
                'subject_version' => $campaign->version,
                'payload_hash' => $campaign->payload_hash,
                'scope' => 'spend',
                'cap_minor' => $campaign->total_budget_minor,
                'currency' => $campaign->currency,
                'status' => MarketingApproval::ACTIVE,
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'delegation_id' => $delegation?->id,
                'approved_at' => now(),
                'expires_at' => now()->addDays(self::APPROVAL_DAYS)->min($campaign->end_at),
            ]);
            $campaign->update(['status' => MarketingCampaign::APPROVED]);
            $this->audit->record($campaign->project, $actor, 'campaign.approved', $campaign, after: ['hash' => $campaign->payload_hash, 'cap_minor' => $campaign->total_budget_minor], meta: [
                'version' => $campaign->version, 'approval' => $approval->uuid, 'delegation' => $delegation?->uuid,
            ]);
            $this->changed($campaign);

            return $approval;
        });
    }

    public function reject(MarketingCampaign $campaign, Model $actor, ?string $note): MarketingCampaign
    {
        $role = $this->access->assert($campaign->project, $actor, 'read');
        if (! $role->isOwner() && ! $this->delegations->valid($this->delegations->activeFor($campaign->project, $actor))) {
            throw new MarketingException('spend_approval_forbidden', 403);
        }
        $this->assertStatus($campaign, [MarketingCampaign::AWAITING_APPROVAL, MarketingCampaign::APPROVED]);
        $this->supersede($campaign);
        $campaign->update(['status' => MarketingCampaign::REJECTED, 'last_error' => $note === null ? null : mb_substr($note, 0, 300)]);
        $this->audit->record($campaign->project, $actor, 'campaign.rejected', $campaign, meta: ['note' => $note]);
        $this->changed($campaign);

        return $campaign;
    }

    /**
     * POST campaigns/{id}/publish — the only way anything reaches a platform.
     *
     * @param  array{approval_id: string, campaign_version: int, expected_budget_minor: int, currency: string}  $data
     * @return array{action: MarketingCampaignAction, campaign: MarketingCampaign, replayed: bool}
     */
    public function publish(MarketingCampaign $campaign, Model $actor, string $key, array $data): array
    {
        $this->access->assert($campaign->project, $actor, 'edit');

        // AT-14: the same key again gives the same answer, and nothing is sent twice.
        $previous = MarketingCampaignAction::query()->where('project_id', $campaign->project_id)->where('idempotency_key', $key)->where('action_type', 'publish')->first();
        if ($previous !== null) {
            if ($previous->campaign_id !== $campaign->id) {
                throw new MarketingException('idempotency_conflict', 409);
            }

            return ['action' => $previous, 'campaign' => $campaign->fresh(), 'replayed' => true];
        }

        [$action, $campaign, $integration] = DB::transaction(function () use ($campaign, $actor, $key, $data) {
            $campaign = MarketingCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($campaign->status === MarketingCampaign::UNKNOWN) {
                throw new MarketingException('reconcile_required', 409);
            }
            $this->assertStatus($campaign, [MarketingCampaign::APPROVED]);
            $approval = $this->assertApproval($campaign, $data);
            $integration = $campaign->integration;
            if ($integration === null || ! in_array($integration->status, [MarketingIntegration::CONNECTED], true)) {
                throw new MarketingException('integration_not_connected', 409);
            }
            if ($integration->token_expires_at !== null && $integration->token_expires_at->isPast()) {
                $this->integrations->markExpired($integration);

                throw new MarketingException('integration_expired', 409);
            }
            $action = MarketingCampaignAction::query()->create([
                'project_id' => $campaign->project_id,
                'campaign_id' => $campaign->id,
                'action_type' => 'publish',
                'idempotency_key' => $key,
                'payload_hash' => $campaign->payload_hash,
                'campaign_version' => $campaign->version,
                'approval_id' => $approval->id,
                'marker' => 'dorr-'.Str::lower(Str::random(10)),
                'request_json' => $campaign->payload(),
                'status' => MarketingCampaignAction::SUBMITTING,
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
            ]);
            $campaign->update(['status' => MarketingCampaign::SUBMITTING]);
            $this->audit->record($campaign->project, $actor, 'campaign.publish_submitted', $campaign, meta: ['action' => $action->uuid, 'key' => $key]);

            return [$action, $campaign, $integration];
        });

        $platform = $this->platforms->get($campaign->provider);
        $payload = $campaign->payload() + ['text' => $this->creativeText($campaign)];
        try {
            $result = $platform->createCampaign((string) $integration->credential_ref, (string) $integration->external_account_ref, $payload, $action->marker);
            $action->update(['status' => MarketingCampaignAction::SUCCEEDED, 'external_ref' => $result['id'], 'response_json' => $result]);
            $campaign->update([
                'status' => MarketingCampaign::PUBLISHED, 'platform_ref' => $result['id'], 'platform_status' => $result['status'],
                'published_at' => now(), 'last_error' => $result['warnings'] === [] ? null : mb_substr(implode('; ', $result['warnings']), 0, 300),
            ]);
            $this->audit->record($campaign->project, $actor, 'campaign.published', $campaign, meta: ['ref' => $result['id'], 'paused' => true]);
        } catch (PlatformException $e) {
            if ($e->kind === PlatformException::UNREACHABLE) {
                $action->update(['status' => MarketingCampaignAction::UNKNOWN, 'error_code' => 'platform_unreachable']);
                $campaign->update(['status' => MarketingCampaign::UNKNOWN, 'last_error' => 'platform_unreachable']);
            } elseif ($e->kind === PlatformException::EXPIRED) {
                $this->integrations->markExpired($integration);
                $action->update(['status' => MarketingCampaignAction::FAILED, 'error_code' => 'integration_expired']);
                $campaign->update(['status' => MarketingCampaign::APPROVED, 'last_error' => 'integration_expired']);
            } else {
                $action->update(['status' => MarketingCampaignAction::FAILED, 'error_code' => 'platform_rejected', 'response_json' => ['message' => mb_substr($e->getMessage(), 0, 300)]]);
                $campaign->update(['status' => MarketingCampaign::FAILED, 'last_error' => mb_substr($e->getMessage(), 0, 300)]);
                $this->supersede($campaign);
            }
            $this->audit->record($campaign->project, $actor, 'campaign.publish_'.strtolower($action->status), $campaign, meta: ['action' => $action->uuid, 'kind' => $e->kind]);
        }
        $this->changed($campaign);

        return ['action' => $action->fresh(), 'campaign' => $campaign->fresh(), 'replayed' => false];
    }

    /** AT-06: find out what happened to an UNKNOWN publish (or refresh a published one's state). */
    public function reconcile(MarketingCampaign $campaign, Model $actor): MarketingCampaign
    {
        $this->access->assert($campaign->project, $actor, 'edit');
        $this->assertStatus($campaign, [MarketingCampaign::UNKNOWN, MarketingCampaign::PUBLISHED]);
        $integration = $campaign->integration;
        if ($integration === null || $integration->credential_ref === null) {
            throw new MarketingException('integration_not_connected', 409);
        }
        $platform = $this->platforms->get($campaign->provider);

        try {
            if ($campaign->status === MarketingCampaign::PUBLISHED) {
                $found = $platform->campaign((string) $integration->credential_ref, (string) $campaign->platform_ref);
                $campaign->update(['platform_status' => $found['status'] ?? $campaign->platform_status]);
            } else {
                $action = $campaign->actions()->where('action_type', 'publish')->where('status', MarketingCampaignAction::UNKNOWN)->latest('id')->firstOrFail();
                $found = $platform->findByMarker((string) $integration->credential_ref, (string) $integration->external_account_ref, $action->marker);
                if ($found !== null) {
                    $action->update(['status' => MarketingCampaignAction::SUCCEEDED, 'external_ref' => $found['id']]);
                    $campaign->update(['status' => MarketingCampaign::PUBLISHED, 'platform_ref' => $found['id'], 'platform_status' => $found['status'], 'published_at' => now(), 'last_error' => null]);
                } else {
                    // Nothing was created: back to APPROVED; a new publish needs a new Idempotency-Key.
                    $action->update(['status' => MarketingCampaignAction::FAILED, 'error_code' => 'not_created']);
                    $campaign->update(['status' => MarketingCampaign::APPROVED, 'last_error' => null]);
                }
            }
        } catch (PlatformException $e) {
            if ($e->kind === PlatformException::EXPIRED) {
                $this->integrations->markExpired($integration);

                throw new MarketingException('integration_expired', 409);
            }

            throw new MarketingException('platform_unreachable', 503);
        }
        $this->audit->record($campaign->project, $actor, 'campaign.reconciled', $campaign, meta: ['status' => $campaign->status]);
        $this->changed($campaign);

        return $campaign->fresh();
    }

    /** Reads the platform's own numbers into the results (basis `actual`, source `platform`, with the time read). */
    public function syncMetrics(MarketingCampaign $campaign, Model $actor): array
    {
        $this->access->assert($campaign->project, $actor, 'research');
        $this->assertStatus($campaign, [MarketingCampaign::PUBLISHED]);
        $integration = $campaign->integration;
        if ($integration === null || ! $integration->usable()) {
            throw new MarketingException($integration?->status === MarketingIntegration::EXPIRED ? 'integration_expired' : 'integration_not_connected', 409);
        }
        $from = ($campaign->published_at ?? $campaign->start_at)->copy()->max(now()->subDays(90))->toDateString();
        try {
            $rows = $this->platforms->get($campaign->provider)->insights((string) $integration->credential_ref, (string) $campaign->platform_ref, $from, now()->toDateString());
        } catch (PlatformException $e) {
            if ($e->kind === PlatformException::EXPIRED) {
                $this->integrations->markExpired($integration);

                throw new MarketingException('integration_expired', 409);
            }

            throw new MarketingException('platform_unreachable', 503);
        }
        $saved = 0;
        DB::transaction(function () use ($rows, $campaign, &$saved) {
            foreach ($rows as $row) {
                $key = MarketingMetric::key('facebook', $row['date'], 'actual', null, $campaign->id);
                MarketingMetric::query()->updateOrCreate(['project_id' => $campaign->project_id, 'row_key' => $key], [
                    'campaign_id' => $campaign->id, 'channel' => 'facebook', 'metric_date' => $row['date'], 'source' => 'platform', 'basis' => 'actual',
                    'impressions' => $row['impressions'], 'clicks' => $row['clicks'], 'spend_minor' => $row['spend_minor'],
                    'conversions' => $row['conversions'], 'revenue_minor' => $row['revenue_minor'], 'currency' => $campaign->currency,
                ]);
                $saved++;
            }
        });
        $campaign->update(['metrics_synced_at' => now()]);
        $integration->update(['last_sync_at' => now()]);
        $this->budgets->checkAlerts($campaign->project, 'ads');
        app(AlertRuleService::class)->check($campaign->project);
        $this->audit->record($campaign->project, $actor, 'campaign.metrics_synced', $campaign, meta: ['rows' => $saved]);

        return ['rows' => $saved, 'synced_at' => now()->toIso8601String()];
    }

    public function delete(MarketingCampaign $campaign, Model $actor): void
    {
        $this->access->assert($campaign->project, $actor, 'edit');
        if (in_array($campaign->status, [MarketingCampaign::SUBMITTING, MarketingCampaign::PUBLISHED, MarketingCampaign::UNKNOWN], true)) {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $campaign->status]);
        }
        $this->supersede($campaign);
        $this->audit->record($campaign->project, $actor, 'campaign.deleted', $campaign, before: $campaign->payload());
        $campaign->delete();
    }

    /** @return list<array{code: string, detail: ?string}> */
    public function issues(MarketingCampaign $campaign): array
    {
        $issues = [];
        $integration = $campaign->integration;
        $country = MarketingCountry::of($campaign->project->owner);
        if ($country === null || ! $this->features->allows($country, 'platform_'.$campaign->provider)) {
            $issues[] = ['code' => 'platform_off', 'detail' => $campaign->provider];
        }
        if ($integration === null || $integration->status === MarketingIntegration::REVOKED || $integration->status === MarketingIntegration::PENDING || $integration->status === MarketingIntegration::ERROR) {
            $issues[] = ['code' => 'integration_not_connected', 'detail' => null];
        } elseif ($integration->status === MarketingIntegration::EXPIRED || ($integration->token_expires_at !== null && $integration->token_expires_at->isPast())) {
            $issues[] = ['code' => 'integration_expired', 'detail' => null];
        } elseif ($integration->account_currency !== null && $integration->account_currency !== $campaign->currency) {
            $issues[] = ['code' => 'currency_mismatch', 'detail' => $integration->account_currency];
        }
        if ($campaign->daily_budget_minor < self::MIN_DAILY_MINOR) {
            $issues[] = ['code' => 'budget_too_low', 'detail' => (string) self::MIN_DAILY_MINOR];
        }
        if ($campaign->start_at->lt(now()->subMinutes(5))) {
            $issues[] = ['code' => 'start_in_past', 'detail' => null];
        }
        if ($campaign->end_at->lte($campaign->start_at) || $campaign->start_at->diffInDays($campaign->end_at) > self::MAX_DAYS) {
            $issues[] = ['code' => 'bad_dates', 'detail' => (string) self::MAX_DAYS];
        }
        if (($campaign->targeting['countries'] ?? []) === []) {
            $issues[] = ['code' => 'no_audience', 'detail' => null];
        }
        $content = $campaign->contentItem;
        if ($campaign->content_item_id !== null && ($content === null || ! in_array($content->status, [MarketingContentItem::APPROVED, MarketingContentItem::SCHEDULED, MarketingContentItem::PUBLISHED], true))) {
            $issues[] = ['code' => 'content_not_approved', 'detail' => null];
        } elseif ($content !== null && ClaimsReviewer::blocking($content->claims ?? [])) {
            $issues[] = ['code' => 'content_has_blocking_issues', 'detail' => null];
        }

        return $issues;
    }

    /** @param  array<string, mixed>  $data */
    private function fill(MarketingCampaign $campaign, MarketingProject $project, array $data, ?MarketingIntegration $integration): void
    {
        $start = Carbon::parse($data['start_at']);
        $end = Carbon::parse($data['end_at']);
        $days = max(1, (int) ceil($start->diffInMinutes($end) / 1440));
        $content = null;
        if (array_key_exists('content', $data)) {
            $content = $data['content'] === null ? null : (MarketingContentItem::query()->where('project_id', $project->id)->where('uuid', $data['content'])->value('id')
                ?? throw new MarketingException('invalid_answer', 422));
        }
        $campaign->fill([
            'integration_id' => $integration?->id,
            'provider' => $integration?->provider ?? $campaign->provider,
            'name' => $data['name'],
            'objective' => $data['objective'],
            'daily_budget_minor' => (int) $data['daily_budget_minor'],
            'total_budget_minor' => (int) $data['daily_budget_minor'] * $days,
            'currency' => $integration?->account_currency ?? $project->budget_currency,
            'start_at' => $start,
            'end_at' => $end,
            'targeting' => [
                'countries' => array_values(array_unique(array_map('strtoupper', $data['targeting']['countries'] ?? []))),
                'age_min' => isset($data['targeting']['age_min']) ? (int) $data['targeting']['age_min'] : null,
                'age_max' => isset($data['targeting']['age_max']) ? (int) $data['targeting']['age_max'] : null,
            ],
        ]);
        if (array_key_exists('content', $data)) {
            $campaign->content_item_id = $content;
        }
    }

    private function integration(MarketingProject $project, ?string $uuid): ?MarketingIntegration
    {
        if ($uuid === null) {
            return null;
        }

        return MarketingIntegration::query()->where('project_id', $project->id)->where('uuid', $uuid)->first()
            ?? throw new MarketingException('integration_not_connected', 422);
    }

    /** POL-01: the approval names this campaign, this version, this payload, a cap that covers it, and is still valid. */
    private function assertApproval(MarketingCampaign $campaign, array $data): MarketingApproval
    {
        $approval = MarketingApproval::query()->where('uuid', $data['approval_id'])->where('subject_type', 'campaign')->where('subject_id', $campaign->id)->first();
        $fail = fn (string $why) => throw new MarketingException('approval_invalid', 409, [], ['reason' => $why]);
        if ($approval === null || $approval->status !== MarketingApproval::ACTIVE) {
            $fail('not_active');
        }
        if ($approval->expires_at !== null && $approval->expires_at->isPast()) {
            $fail('expired');
        }
        if ((int) $data['campaign_version'] !== $campaign->version || $approval->subject_version !== $campaign->version) {
            $fail('version');
        }
        if ($approval->payload_hash !== MarketingAudit::hash($campaign->payload())) {
            $fail('payload');
        }
        if ((int) $data['expected_budget_minor'] !== $campaign->total_budget_minor || $campaign->total_budget_minor > (int) $approval->cap_minor) {
            $fail('budget');
        }
        if (strtoupper((string) $data['currency']) !== $campaign->currency || $approval->currency !== $campaign->currency) {
            $fail('currency');
        }
        if ($approval->delegation_id !== null) {
            $delegation = MarketingApproval::query()->find($approval->delegation_id);
            if (! $this->delegations->valid($delegation)) {
                $fail('delegation');
            }
        }

        return $approval;
    }

    /** @param  list<string>  $allowed */
    private function assertStatus(MarketingCampaign $campaign, array $allowed): void
    {
        if (! in_array($campaign->status, $allowed, true)) {
            throw new MarketingException('invalid_transition', 409, [], ['status' => $campaign->status]);
        }
    }

    private function supersede(MarketingCampaign $campaign): int
    {
        return MarketingApproval::query()->where('subject_type', 'campaign')->where('subject_id', $campaign->id)->where('status', MarketingApproval::ACTIVE)
            ->update(['status' => MarketingApproval::SUPERSEDED, 'updated_at' => now()]);
    }

    private function creativeText(MarketingCampaign $campaign): ?string
    {
        $content = $campaign->contentItem;
        if ($content === null) {
            return null;
        }
        $variant = collect($content->content['variants'] ?? [])->firstWhere('key', $content->content['selected'] ?? 'A');

        return $variant === null ? null : trim(($variant['headline'] ?? '')."\n".($variant['body'] ?? ''));
    }

    private function notifyApprovers(MarketingCampaign $campaign): void
    {
        try {
            $owner = $campaign->project->owner;
            $delegates = MarketingApproval::query()->where('project_id', $campaign->project_id)->where('scope', 'delegation')
                ->where('status', MarketingApproval::ACTIVE)->where('expires_at', '>', now())->get()
                ->map(fn ($d) => MarketingProjectMember::query()->where('project_id', $campaign->project_id)->where('member_type', $d->delegate_type)->where('member_id', $d->delegate_id)->with('member')->first()?->member)
                ->filter();
            $this->notifications->send(
                collect([$owner])->merge($delegates)->filter()->unique(fn ($m) => $m->getMorphClass().$m->getKey())->values(),
                'marketing.campaign.approval',
                'marketing_campaign_approval_title',
                'marketing_campaign_approval_body',
                ['project' => $campaign->project->name, 'campaign' => $campaign->name],
                ['type' => 'marketing_campaign', 'project_id' => $campaign->project->uuid, 'campaign_id' => $campaign->uuid],
            );
        } catch (Throwable $e) {
            Log::warning('[Marketing] approval notice failed: '.$e->getMessage());
        }
    }

    private function changed(MarketingCampaign $campaign): void
    {
        try {
            event(new MarketingChanged($campaign->project_id, 'campaign', [
                'project_id' => $campaign->project?->uuid, 'campaign_id' => $campaign->uuid, 'status' => $campaign->status,
            ]));
        } catch (Throwable $e) {
            Log::warning('[Marketing] campaign push failed: '.$e->getMessage());
        }
    }
}
