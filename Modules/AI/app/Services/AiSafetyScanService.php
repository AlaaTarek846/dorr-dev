<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiSafetyScanResource;
use Modules\AI\Repositories\AiSafetyScanRepository;

class AiSafetyScanService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiSafetyScanResource::class;

    public function __construct(AiSafetyScanRepository $repository)
    {
        parent::__construct($repository);
    }
}
