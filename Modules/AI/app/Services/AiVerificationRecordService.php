<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiVerificationResource;
use Modules\AI\Repositories\AiVerificationRepository;

/**
 * Read-only admin listing for ai_verifications (the audit trail written by
 * AiVerificationEngine). Named "...RecordService" rather than
 * "AiVerificationService" to keep it clearly separate from the engine that
 * actually performs verification.
 */
class AiVerificationRecordService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiVerificationResource::class;

    public function __construct(AiVerificationRepository $repository)
    {
        parent::__construct($repository);
    }
}
