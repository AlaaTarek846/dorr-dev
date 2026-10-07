<?php

namespace Modules\AI\Services\Sites;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Exceptions\AiSubscriptionException;
use Modules\AI\Jobs\GenerateAiSiteVersionJob;
use Modules\AI\Models\AiSiteOffer;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Models\AiSitePurchase;
use Modules\AI\Models\AiSiteVersion;
use Modules\AI\Services\AiSubscriptionBillingService;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\InsufficientBalanceException;
use ZipArchive;

/** The customer-facing operations on a site: create (plan or purchase), edit, retry, restore, delete, download. */
class AiSiteProjectService
{
    public function __construct(
        protected AiSiteEntitlementService $entitlements,
        protected AiSiteStorage $storage,
        protected AiSiteTokenizer $tokenizer,
        protected AiSubscriptionBillingService $billing,
    ) {}

    /**
     * @param  array<string, mixed>  $brief  normalised form data (see AiSiteProjectStoreRequest::brief())
     * @param  list<UploadedFile>  $images
     */
    public function create(Authenticatable $owner, array $brief, ?UploadedFile $logo, array $images, ?int $offerId = null): AiSiteProject
    {
        $this->assertEnabled();

        $plan = $this->entitlements->activePlanFor($owner);
        $refusal = $this->entitlements->planCreateRefusal($owner, $plan);

        // An explicit offer id always means "buy this"; without one the plan must allow it.
        if ($offerId === null && $refusal !== null) {
            throw new AiSiteException($refusal, $refusal === 'daily_limit_reached' ? 429 : 403);
        }

        [$project, $version] = DB::transaction(function () use ($owner, $brief, $logo, $images, $offerId) {
            $purchase = $offerId !== null ? $this->purchase($owner, $offerId) : null;

            $project = AiSiteProject::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getAuthIdentifier(),
                'title' => Str::limit((string) $brief['title'], 150, ''),
                'slug' => Str::random(40),
                'status' => AiSiteProject::STATUS_GENERATING,
                'access_type' => $purchase ? AiSiteProject::ACCESS_PURCHASE : AiSiteProject::ACCESS_PLAN,
                'purchase_id' => $purchase?->id,
                'brief' => $brief,
            ]);

            $assets = [];

            if ($logo !== null) {
                $assets[] = ['role' => 'logo', 'path' => $this->storage->storeAsset($project, $logo, 'logo')];
            }

            foreach (array_values($images) as $index => $image) {
                $assets[] = ['role' => 'image', 'path' => $this->storage->storeAsset($project, $image, 'image-'.($index + 1))];
            }

            $project->forceFill(['brief' => $brief + ['assets' => $assets]])->save();

            if ($purchase !== null) {
                $this->entitlements->consumePurchase($purchase);
            }

            $version = $this->newVersion($project, AiSiteVersion::KIND_GENERATE, null);

            return [$project, $version];
        });

        GenerateAiSiteVersionJob::dispatch($version->id);

        return $project->fresh(['versions']);
    }

    /** Same brief, new attempt - for a first build that failed. */
    public function retry(AiSiteProject $project, Authenticatable $owner): AiSiteProject
    {
        if ($project->current_version_id !== null || $project->status !== AiSiteProject::STATUS_FAILED) {
            throw new AiSiteException('retry_not_needed', 409);
        }

        return $this->queueVersion($project, $owner, AiSiteVersion::KIND_GENERATE, null);
    }

    public function requestEdit(AiSiteProject $project, Authenticatable $owner, string $instruction): AiSiteProject
    {
        if ($project->current_version_id === null) {
            throw new AiSiteException('no_version_yet', 409);
        }

        return $this->queueVersion($project, $owner, AiSiteVersion::KIND_EDIT, trim($instruction));
    }

    /** Makes an older version current again as a NEW version (history is never rewritten). Uses no AI allowance. */
    public function restore(AiSiteProject $project, int $number): AiSiteProject
    {
        $source = $project->versions()->where('number', $number)->where('status', AiSiteVersion::STATUS_COMPLETED)->first();

        if ($source === null) {
            throw new AiSiteException('version_not_found', 404);
        }

        return DB::transaction(function () use ($project, $source) {
            $locked = AiSiteProject::query()->lockForUpdate()->findOrFail($project->id);
            $this->assertCanChange($locked);

            if ($locked->current_version_id === $source->id) {
                throw new AiSiteException('already_current', 409);
            }

            $version = $this->newVersion($locked, AiSiteVersion::KIND_RESTORE, null, counted: false);
            $written = $this->storage->writeVersion($version, $this->storage->versionFiles($source));

            $version->forceFill([
                'status' => AiSiteVersion::STATUS_COMPLETED,
                'files' => $written['manifest'],
                'total_bytes' => $written['total'],
                'completed_at' => now(),
            ])->save();

            $locked->forceFill(['current_version_id' => $version->id, 'status' => AiSiteProject::STATUS_READY, 'last_error' => null])->save();

            return $locked->fresh(['versions']);
        });
    }

    /** @param  bool  $force  admin removal: takes a live hosting down with the site instead of refusing */
    public function delete(AiSiteProject $project, bool $force = false): void
    {
        $hosting = $project->hosting()->first();

        if ($hosting !== null && $hosting->isLive() && ! $force) {
            throw new AiSiteException('hosting_active', 409);
        }

        $hosting?->delete();
        $this->storage->deleteProject($project);
        $project->delete();
    }

    /**
     * Zip of the current site with the real contact details filled in plus the
     * uploaded assets. Returns the temp file path; the caller deletes it.
     */
    public function zip(AiSiteProject $project): string
    {
        $version = $project->currentVersion;

        if ($version === null) {
            throw new AiSiteException('no_version_yet', 409);
        }

        $map = $this->tokenizer->map((array) $project->brief);
        $path = tempnam(sys_get_temp_dir(), 'aisite');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new AiSiteException('zip_failed', 500);
        }

        foreach ($this->storage->versionFiles($version) as $file => $content) {
            $zip->addFromString($file, $this->tokenizer->apply($content, $map));
        }

        foreach ($this->storage->assetNames($project) as $name) {
            $bytes = $this->storage->readAsset($project, 'assets/'.$name);

            if ($bytes !== null) {
                $zip->addFromString('assets/'.$name, $bytes);
            }
        }

        $zip->close();

        return $path;
    }

    protected function queueVersion(AiSiteProject $project, Authenticatable $owner, string $kind, ?string $instruction): AiSiteProject
    {
        $this->assertEnabled();

        $version = DB::transaction(function () use ($project, $owner, $kind, $instruction) {
            $locked = AiSiteProject::query()->lockForUpdate()->findOrFail($project->id);
            $this->assertCanChange($locked);
            $this->entitlements->assertCanGenerate($locked, $owner);

            if ($locked->access_type === AiSiteProject::ACCESS_PURCHASE && $locked->purchase !== null) {
                $this->entitlements->consumePurchase($locked->purchase);
            }

            $version = $this->newVersion($locked, $kind, $instruction);
            $locked->forceFill(['status' => AiSiteProject::STATUS_GENERATING])->save();

            return $version;
        });

        GenerateAiSiteVersionJob::dispatch($version->id);

        return $project->fresh(['versions']);
    }

    protected function assertCanChange(AiSiteProject $project): void
    {
        if ($project->isDisabled()) {
            throw new AiSiteException('project_disabled', 403);
        }

        if ($project->status === AiSiteProject::STATUS_GENERATING) {
            throw new AiSiteException('project_busy', 409);
        }

        if ($project->versions()->count() >= (int) config('ai.sites.max_versions', 30)) {
            throw new AiSiteException('versions_limit', 422);
        }
    }

    protected function newVersion(AiSiteProject $project, string $kind, ?string $instruction, bool $counted = true): AiSiteVersion
    {
        $number = (int) $project->versions()->max('number') + 1;

        return AiSiteVersion::query()->create([
            'project_id' => $project->id,
            'number' => $number,
            'kind' => $kind,
            'status' => AiSiteVersion::STATUS_PENDING,
            'instruction' => $instruction,
            'counted' => $counted,
        ]);
    }

    protected function purchase(Authenticatable $owner, int $offerId): AiSitePurchase
    {
        $offer = AiSiteOffer::query()->where('is_active', true)->find($offerId);

        if ($offer === null) {
            throw new AiSiteException('offer_unavailable', 404);
        }

        $country = currentCountry();
        $price = $offer->priceFor($country);

        if ($price === null) {
            throw new AiSiteException('offer_unavailable', 404);
        }

        try {
            $wallet = $this->billing->walletFor($owner, $country);
            $this->billing->assertCurrencyMatches($wallet, $price['currency']);
            $result = $this->billing->charge($wallet, $price['price'], WalletTransactionType::ServicePayment, [
                'reference_type' => 'ai_site_offer',
                'reference_id' => $offer->id,
            ]);
        } catch (InsufficientBalanceException) {
            throw new AiSiteException('insufficient_balance', 402);
        } catch (AiSubscriptionException $e) {
            throw new AiSiteException($e->getMessage() === 'subscription_currency_mismatch' ? 'currency_mismatch' : 'country_not_resolved', 422);
        }

        return AiSitePurchase::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'offer_id' => $offer->id,
            'amount' => $price['price'],
            'currency' => $price['currency'],
            'wallet_operation_id' => $result['operation_id'] ?? null,
            'generations_included' => $offer->generations_included,
            'generations_used' => 0,
            'status' => 'active',
        ]);
    }

    protected function assertEnabled(): void
    {
        if (! config('ai.sites.enabled', true)) {
            throw new AiSiteException('disabled', 503);
        }
    }
}
