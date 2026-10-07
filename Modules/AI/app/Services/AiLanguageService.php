<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiLanguageResource;
use Modules\AI\Repositories\AiLanguageRepository;

class AiLanguageService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiLanguageResource::class;

    public function __construct(AiLanguageRepository $repository)
    {
        parent::__construct($repository);
    }
}
