<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiLocaleResource;
use Modules\AI\Repositories\AiLocaleRepository;

class AiLocaleService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiLocaleResource::class;

    public function __construct(AiLocaleRepository $repository)
    {
        parent::__construct($repository);
    }
}
