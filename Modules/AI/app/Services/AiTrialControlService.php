<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiTrialControlResource;
use Modules\AI\Repositories\AiTrialControlRepository;

class AiTrialControlService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiTrialControlResource::class;

    public function __construct(AiTrialControlRepository $repository)
    {
        parent::__construct($repository);
    }
}
